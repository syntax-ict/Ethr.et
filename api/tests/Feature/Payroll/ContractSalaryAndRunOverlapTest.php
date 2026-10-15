<?php

declare(strict_types=1);

use App\Enums\ContractStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollEntry;
use App\Services\EmployeeContractService;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Production QA 2026-10-15, findings F1 and F2
 * (docs/audit/QA-PRODUCTION-2026-10-15.md).
 *
 * F1: a 15,000 ETB contract was recorded on an employee and the run over that
 * period completed with 0 employees — the contract form wrote salary_cents to
 * the contract row only, the engine read employees.salary_cents, and anyone at
 * 0 was skipped in silence. Three halves close it: the contract service syncs
 * the employee's salary when the contract is already in force, the engine
 * falls back to the contract in force during the period, and whatever is still
 * skipped is counted on the run instead of vanishing.
 *
 * F2: two runs over the same period were both accepted; only the idempotency
 * key deduplicated. One live run per period now, with voided and failed runs
 * not blocking, and replay untouched.
 */
function overlapPayload(string $key, string $start = '2026-06-01', string $end = '2026-06-30'): array
{
    return [
        'period_start' => $start,
        'period_end' => $end,
        'idempotency_key' => $key,
    ];
}

// ── F1a: the contract service syncs the employee's salary ──

test('a contract in force sets the salary payroll reads', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);

    app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent',
        'start_date' => now()->subDays(10)->toDateString(),
        'salary_cents' => 1500000,
    ], null);

    expect($employee->fresh()->salary_cents)->toBe(1500000);
});

test('a future-dated contract does not change the salary early', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent',
        'start_date' => now()->addMonth()->toDateString(),
        'salary_cents' => 1500000,
    ], null);

    expect($employee->fresh()->salary_cents)->toBe(500000);
});

test('renewing with a new salary syncs it too', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1000000]);

    $service = app(EmployeeContractService::class);
    $contract = $service->create($employee, [
        'contract_type' => 'fixed_term',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
        'salary_cents' => 1000000,
    ], null);

    $service->renew($contract, [
        'contract_type' => 'fixed_term',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'salary_cents' => 1200000,
    ], null);

    expect($employee->fresh()->salary_cents)->toBe(1200000);
});

// ── F1b: the engine falls back to the contract in force during the period ──

test('an employee with no salary of their own is paid what the period contract states', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);

    // Written directly, bypassing the service sync, the way every contract
    // row created before the sync existed looks.
    EmployeeContract::create([
        'employee_id' => $employee->id,
        'contract_type' => 'permanent',
        'start_date' => '2026-05-01',
        'salary_cents' => 1500000,
        'status' => ContractStatus::ACTIVE->value,
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    expect($run->employee_count)->toBe(1)
        ->and($run->skipped_no_salary)->toBe(0);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();
    expect($entry->basic_salary_cents)->toBe(1500000);
});

test('a contract that started after the period does not pay it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);

    EmployeeContract::create([
        'employee_id' => $employee->id,
        'contract_type' => 'permanent',
        'start_date' => '2026-08-01',
        'salary_cents' => 1500000,
        'status' => ContractStatus::ACTIVE->value,
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    expect($run->employee_count)->toBe(0)
        ->and($run->skipped_no_salary)->toBe(1);
});

test('the employee\'s own salary wins over the contract when both exist', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 2000000]);

    EmployeeContract::create([
        'employee_id' => $employee->id,
        'contract_type' => 'permanent',
        'start_date' => '2026-05-01',
        'salary_cents' => 1500000,
        'status' => ContractStatus::ACTIVE->value,
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();
    expect($entry->basic_salary_cents)->toBe(2000000);
});

// ── F1c: what is still skipped is counted, not silent ──

test('a run counts the employees it skipped for having no salary anywhere', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800000]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    expect($run->employee_count)->toBe(1)
        ->and($run->skipped_no_salary)->toBe(2);

    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}")
        ->assertOk()
        ->assertJsonPath('skipped_no_salary', 2);
});

// ── F2: one live run per period ──

test('a second run over the same period is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process";

    // The endpoint allows 2 requests/hour per tenant, so each test here
    // spends at most two posts; the other overlap shapes seed through the
    // engine directly.
    test()->postJson($url, overlapPayload('overlap-first'))->assertStatus(202);

    test()->postJson($url, overlapPayload('overlap-second'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['period_start']);
});

test('a partial overlap is an overlap', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    );

    // The new period starts inside the existing one.
    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process",
        overlapPayload('overlap-partial', '2026-06-15', '2026-07-14'),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors(['period_start']);
});

test('a voided run does not block the period being run again', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
        'void-then-rerun',
    )->run;
    $engine->void($run, $user->id, 'QA');

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process",
        overlapPayload('after-void'),
    )->assertStatus(202);
});

test('replaying the same idempotency key still answers with the existing run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process";

    test()->postJson($url, overlapPayload('replay-key'))->assertStatus(202);

    test()->postJson($url, overlapPayload('replay-key'))
        ->assertStatus(200)
        ->assertJsonPath('was_duplicate', true);
});

test('another organisation\'s run never blocks this one', function () {
    $other = createTenant(['subdomain' => 'other-org']);
    $otherUser = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $other);
    Employee::factory()->create(['tenant_id' => $other->id, 'salary_cents' => 500000]);
    app(PayrollEngine::class)->process(
        $other->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $otherUser->id,
    );
    app('auth')->forgetGuards();

    $tenant = createTenant(['subdomain' => 'this-org']);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process",
        overlapPayload('cross-tenant-ok'),
    )->assertStatus(202);
});
