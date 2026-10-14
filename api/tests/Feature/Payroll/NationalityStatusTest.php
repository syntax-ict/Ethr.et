<?php

declare(strict_types=1);

use App\Enums\NationalityStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use App\Support\NationalityBackfill;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pension coverage turns on a fixed nationality status, not the free text
 * (2026-10-14). A typo in the free text — "Ethiopean" — used to exempt an
 * Ethiopian from pension silently, leaving the employer owing the
 * contributions plus 5 % a month (research P12).
 */
function natEmployee(Tenant $tenant, string $code, array $extra = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id, 'employee_code' => $code, 'salary_cents' => 600000,
        'hire_date' => '2025-01-01', 'status' => 'confirmed', ...$extra,
    ]);
}

function natRun(Tenant $tenant, int $userId): PayrollRun
{
    return app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $userId)->run;
}

function natPension(PayrollRun $run, string $code): array
{
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)
        ->whereHas('employee', fn ($q) => $q->where('employee_code', $code))->sole();

    return collect($entry->calculation_log['steps'])->firstWhere('step', 'pension');
}

it('decides coverage by the status alone — a typo in the free text exempts nobody', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    natEmployee($tenant, 'TYPO', ['nationality' => 'Ethiopean', 'nationality_status' => 'ethiopian']);
    natEmployee($tenant, 'NAT', ['nationality' => 'Ethiopian national']);
    natEmployee($tenant, 'FOR', ['nationality' => 'Ethiopian', 'nationality_status' => 'foreign']);
    natEmployee($tenant, 'ORI', ['nationality' => 'American', 'nationality_status' => 'ethiopian_origin']);

    $run = natRun($tenant, $user->id);

    expect(natPension($run, 'TYPO'))->toMatchArray(['exempt' => false, 'employee_pension_cents' => 42000])
        ->and(natPension($run, 'NAT'))->toMatchArray(['exempt' => false, 'employee_pension_cents' => 42000])
        ->and(natPension($run, 'FOR'))->toMatchArray(['exempt' => true, 'exempt_reason' => 'non_national'])
        ->and(natPension($run, 'ORI'))->toMatchArray(['exempt' => true, 'exempt_reason' => 'ethiopian_origin_unconfirmed']);
});

it('maps every known Ethiopian spelling and blank to ethiopian, and flags the rest without guessing', function () {
    $tenant = createTenant();
    $known = ['Ethiopian', 'ethiopia', ' ET ', 'eth', 'ኢትዮጵያዊ', 'ኢትዮጵያዊት', 'ኢትዮጵያ', '', null];
    $flagged = ['Ethiopean', 'Ethiopian national', 'Kenyan', 'Eritrean'];
    foreach ([...$known, ...$flagged] as $i => $value) {
        natEmployee($tenant, "E{$i}", ['nationality' => $value]);
    }
    // As the rows stood before the column existed.
    DB::table('employees')->update(['nationality_status' => 'ethiopian', 'nationality_needs_review' => false]);

    $result = NationalityBackfill::run();

    expect($result['ethiopian'])->toBe(count($known))
        ->and($result['flagged'])->toBe(count($flagged));
    $byValue = Employee::query()->get()->mapWithKeys(fn (Employee $e) => [(string) $e->nationality => [$e->nationality_status, $e->nationality_needs_review]]);
    foreach ($flagged as $value) {
        expect($byValue[$value])->toBe([NationalityStatus::FOREIGN, true]);
    }
    expect($byValue['Ethiopian'])->toBe([NationalityStatus::ETHIOPIAN, false]);
});

it('takes the status from the form and the API, and saving it clears the review flag', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = natEmployee($tenant, 'R1', ['nationality' => 'Ethiopean', 'nationality_status' => 'foreign', 'nationality_needs_review' => true]);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/employees";

    test()->getJson("{$base}?filter[nationality_needs_review]=1")->assertOk()->assertJsonCount(1, 'data');

    test()->putJson("{$base}/{$employee->public_id}", ['nationality_status' => 'stateless'])->assertUnprocessable();
    test()->putJson("{$base}/{$employee->public_id}", ['nationality_status' => 'ethiopian'])->assertOk()
        ->assertJsonPath('nationality_status', 'ethiopian')
        ->assertJsonPath('nationality_needs_review', false);

    test()->getJson("{$base}?filter[nationality_needs_review]=1")->assertOk()->assertJsonCount(0, 'data');

    test()->postJson($base, ['name' => 'New Hire', 'hire_date' => '2026-10-01'])->assertCreated()
        ->assertJsonPath('nationality_status', 'ethiopian');
});

it('imports the status, accepts it as people write it, and refuses an unknown value', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preview = test()->postJson('/api/v1/employees/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('employees.csv',
            "name,hire_date,nationality,nationality_status\n"
            ."Abebe,2024-01-15,Ethiopia,Ethiopian\n"
            ."Sara,2024-01-15,United States,Ethiopian origin\n"
            ."Liam,2024-01-15,Kenya,FOREIGN\n"
            ."Typo,2024-01-15,Ethiopia,Ethiopean\n"
            ."Blank,2024-01-15,,\n"),
    ])->assertOk();

    expect(array_map('strval', array_keys($preview->json('errors'))))->toBe(['5'])
        ->and(implode(' ', $preview->json('errors.5')))->toContain('nationality status');

    $rows = array_values(array_filter($preview->json('rows'), fn ($r) => $r['name'] !== 'Typo'));
    test()->postJson('/api/v1/employees/import/commit', ['import_key' => 'nat-1', 'rows' => $rows])
        ->assertCreated()->assertJsonPath('created', 4);

    expect(Employee::query()->pluck('nationality_status', 'name')->map->value->all())->toMatchArray([
        'Abebe' => 'ethiopian', 'Sara' => 'ethiopian_origin', 'Liam' => 'foreign', 'Blank' => 'ethiopian',
    ]);
});

it('shows the exemptions on the run and will not approve until their count is acknowledged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    natEmployee($tenant, 'ET1');
    $foreign = natEmployee($tenant, 'FOR', ['nationality_status' => 'foreign']);
    natEmployee($tenant, 'FO2', ['nationality_status' => 'foreign']);
    natEmployee($tenant, 'ORI', ['nationality_status' => 'ethiopian_origin']);
    $run = natRun($tenant, $user->id);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}";

    test()->getJson("{$url}/register")->assertOk()
        ->assertJsonPath('pension_exemptions.count', 3)
        ->assertJsonPath('pension_exemptions.by_reason.non_national', 2)
        ->assertJsonPath('pension_exemptions.by_reason.ethiopian_origin_unconfirmed', 1)
        ->assertJsonFragment(['employee_public_id' => $foreign->public_id, 'reason' => 'non_national']);

    test()->putJson("{$url}/approve")->assertStatus(422)->assertJsonPath('pension_exemptions', 3);
    test()->putJson("{$url}/approve", ['acknowledged_pension_exemptions' => 2])->assertStatus(422);
    test()->putJson("{$url}/approve", ['acknowledged_pension_exemptions' => 3])->assertOk();

    expect($run->fresh()->status)->toBe('approved');
});

it('approves a run with no exemptions as before', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    natEmployee($tenant, 'ET1');
    $run = natRun($tenant, $user->id);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});
