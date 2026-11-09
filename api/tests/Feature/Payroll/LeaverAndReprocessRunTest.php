<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Review of 2026-11-09. A leaving recorded ahead of its date dropped the
 * person from the months before it, and reprocessing a voided run never asked
 * whether the period already had a live one, so a period could be paid twice.
 */
function juneRun(int $tenantId, int $userId): PayrollRun
{
    return app(PayrollEngine::class)->process($tenantId, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $userId)->run;
}

test('a leaving dated after the period is paid the whole period', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Retirement entered in June, effective at the end of July.
    $retiring = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1_000_000,
        'status' => EmployeeStatus::RETIRED,
        'termination_date' => '2026-07-31',
    ]);

    $run = juneRun($tenant->id, $user->id);

    expect($run->employee_count)->toBe(1)
        ->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $retiring->id)->sole()->basic_salary_cents)
        ->toBe(1_000_000);
});

test('someone who left before the period is still not paid for it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1_000_000,
        'status' => EmployeeStatus::RESIGNED,
        'termination_date' => '2026-05-15',
    ]);

    expect(juneRun($tenant->id, $user->id)->employee_count)->toBe(0);
});

test('a voided run reprocessed a second time with a new key is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 700_000]);
    $voided = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id, 'status' => 'voided', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
    ]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voided->public_id}/reprocess";

    test()->postJson($url, ['idempotency_key' => 'redo-1'])->assertCreated();
    test()->postJson($url, ['idempotency_key' => 'redo-2'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/period-overlaps');

    expect(PayrollRun::query()->where('tenant_id', $tenant->id)->where('status', '!=', 'voided')->count())->toBe(1);
});

test('a voided run cannot be reprocessed once the period was processed afresh', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 700_000]);
    $voided = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id, 'status' => 'voided', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
    ]);
    juneRun($tenant->id, $user->id);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voided->public_id}/reprocess", ['idempotency_key' => 'redo-late'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/period-overlaps');

    expect(PayrollRun::query()->where('tenant_id', $tenant->id)->where('status', '!=', 'voided')->count())->toBe(1);
});
