<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\Analytics\ExecutiveDashboardService;
use Carbon\Carbon;

// Headcount, headcount trend and turnover now share one definition: employed
// on a day means hired on or before it and not yet left. Until 2026-10-20
// turnover dated exits by `updated_at` and divided by every record ever made,
// and the trend rebuilt past months from today's status (blueprint RP3, RP24,
// RP26). Report and register CSVs carry a UTF-8 byte-order mark so Excel reads
// Amharic; payment and filing formats do not (RP20).

afterEach(function () {
    Carbon::setTestNow();
});

it('counts an exit in the period it happened, against the average headcount', function () {
    Carbon::setTestNow('2026-11-05 09:00');
    $tenant = createTenant();
    $make = fn (array $attrs) => Employee::factory()->create(['tenant_id' => $tenant->id, ...$attrs]);

    $make(['hire_date' => '2020-01-01', 'status' => EmployeeStatus::CONFIRMED]);
    $make(['hire_date' => '2020-01-01', 'status' => EmployeeStatus::RESIGNED, 'termination_date' => '2026-10-10']);
    // Left in May, but edited in October: not an October exit.
    $make(['hire_date' => '2020-01-01', 'status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-05-31', 'updated_at' => '2026-10-15']);
    $make(['hire_date' => '2026-10-15', 'status' => EmployeeStatus::PROBATION]);

    $turnover = app(ExecutiveDashboardService::class)
        ->overview($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['turnover'];

    // Employed on 1 October: the confirmed one and the October leaver (2).
    // On 31 October: the confirmed one and the October hire (2). 1 / 2.
    expect($turnover)->toBe(['exits' => 1, 'rate' => 50.0]);
});

it('counts past months by who was employed then, not by today\'s status', function () {
    Carbon::setTestNow('2026-10-20 09:00');
    $tenant = createTenant();
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'hire_date' => '2026-01-01',
        'status' => EmployeeStatus::RESIGNED, 'termination_date' => '2026-08-15',
    ]);

    $trend = collect(app(ExecutiveDashboardService::class)->workforceDeepDive($tenant->id)['headcount_trend'])
        ->pluck('count', 'month');

    expect($trend['2026-07'])->toBe(1)
        ->and($trend['2026-08'])->toBe(0)
        ->and($trend['2026-10'])->toBe(0);
});

it('gives report CSVs the mark Excel needs to read Amharic', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'አበበ ከበደ']);

    $csv = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/export", [
        'source' => 'employees', 'columns' => ['name'], 'format' => 'csv',
    ])->assertOk()->getContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('አበበ ከበደ');
});

it('gives the payroll register the mark, and the bank file none', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}";

    $register = test()->get("{$base}/register-csv")->assertOk()->getContent();
    $bank = test()->get("{$base}/export/bank-csv")->assertOk()->getContent();

    expect(str_starts_with($register, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(str_starts_with($bank, "\xEF\xBB\xBF"))->toBeFalse();
});
