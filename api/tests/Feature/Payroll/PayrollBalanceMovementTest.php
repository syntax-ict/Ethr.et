<?php

declare(strict_types=1);

use App\Enums\CostSharingStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessPayrollJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Loan and cost-sharing balances across the whole life of a run: process,
 * approve, void, reprocess and failure.
 *
 * Until 2026-10-17 the engine moved both balances while it *calculated* a run.
 * A void restored nothing and a reprocess deducted again, so every redo of a
 * period drew an employee's loan down twice; a job that crashed part-way left
 * some balances reduced under a run marked `failed`; and a loan with less
 * outstanding than its instalment was charged the full instalment on the
 * payslip while the balance merely floored at zero. Balances now move when a
 * run is approved, by exactly what its calculation_log says, and an approved
 * run that is voided gives exactly that back.
 *
 * Gross is 8,000.00 ETB with no attendance, so every figure is hand-checkable:
 * the loan instalment is 200.00 and cost sharing at 10 % is 800.00.
 */
function balanceEmployee(Tenant $tenant, array $loan = [], array $costSharing = []): Employee
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 800000,
        'hire_date' => '2020-01-01',
    ]);

    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 240000,
        'remaining_cents' => 240000,
        'monthly_deduction_cents' => 20000,
        'status' => 'active',
        ...$loan,
    ]);

    EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'deduction_rate_percent' => 10,
        'total_obligation_cents' => 5000000,
        'outstanding_cents' => 5000000,
        ...$costSharing,
    ]);

    return $employee;
}

function processBalanceRun(Tenant $tenant, int $userId): PayrollRun
{
    return app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;
}

function runUrl(Tenant $tenant, PayrollRun $run, string $action): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/{$action}";
}

function loanOf(Employee $employee): EmployeeLoan
{
    return EmployeeLoan::where('employee_id', $employee->id)->firstOrFail();
}

function costSharingOf(Employee $employee): EmployeeCostSharing
{
    return EmployeeCostSharing::where('employee_id', $employee->id)->firstOrFail();
}

test('calculating a run moves no balance until it is approved', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant);

    $run = processBalanceRun($tenant, $user->id);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
    expect($entry->other_deductions_cents)->toBe(20000 + 80000);
    expect(loanOf($employee)->remaining_cents)->toBe(240000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(5000000);

    test()->putJson(runUrl($tenant, $run, 'approve'))->assertOk();

    expect(loanOf($employee)->remaining_cents)->toBe(220000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(4920000);
    expect(AuditLog::where('action', 'payroll.balances_applied')->exists())->toBeTrue();
});

test('void before approval and reprocess draws the balances down once', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant);

    $first = processBalanceRun($tenant, $user->id);

    test()->postJson(runUrl($tenant, $first, 'void'), ['reason' => 'Wrong allowance'])->assertOk();
    test()->postJson(runUrl($tenant, $first, 'reprocess'), ['idempotency_key' => 'redo-june'])->assertCreated();

    $second = PayrollRun::where('reprocessed_from_id', $first->id)->firstOrFail();
    test()->putJson(runUrl($tenant, $second, 'approve'))->assertOk();

    expect(loanOf($employee)->remaining_cents)->toBe(220000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(4920000);
});

test('void after approval gives back exactly what the run took, and reprocess takes it once', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant);

    $first = processBalanceRun($tenant, $user->id);
    test()->putJson(runUrl($tenant, $first, 'approve'))->assertOk();
    expect(loanOf($employee)->remaining_cents)->toBe(220000);

    test()->postJson(runUrl($tenant, $first, 'void'), ['reason' => 'Wrong allowance'])->assertOk();

    expect(loanOf($employee)->remaining_cents)->toBe(240000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(5000000);
    expect(AuditLog::where('action', 'payroll.balances_reversed')->exists())->toBeTrue();

    test()->postJson(runUrl($tenant, $first, 'reprocess'), ['idempotency_key' => 'redo-june'])->assertCreated();
    $second = PayrollRun::where('reprocessed_from_id', $first->id)->firstOrFail();
    test()->putJson(runUrl($tenant, $second, 'approve'))->assertOk();

    expect(loanOf($employee)->remaining_cents)->toBe(220000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(4920000);
});

test('a loan owing less than its instalment deducts only what is owed', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant, ['remaining_cents' => 5000]);

    $run = processBalanceRun($tenant, $user->id);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
    $loanLine = collect($entry->deductions)->firstWhere('type', 'loan');
    expect($loanLine['amount_cents'])->toBe(5000);
    expect($entry->calculation_log['outputs']['loan_deduction_cents'])->toBe(5000);
    expect($entry->other_deductions_cents)->toBe(5000 + 80000);
    expect($entry->net_cents)->toBe(
        $entry->gross_cents - $entry->income_tax_cents - $entry->employee_pension_cents - 85000,
    );

    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'loan_deductions');
    expect($step['loans'])->toHaveCount(1);
    expect($step['loans'][0])->toMatchArray([
        'loan_id' => loanOf($employee)->public_id,
        'monthly_deduction_cents' => 20000,
        'remaining_before_cents' => 5000,
        'deduction_cents' => 5000,
        'remaining_after_cents' => 0,
    ]);

    test()->putJson(runUrl($tenant, $run, 'approve'))->assertOk();

    $loan = loanOf($employee);
    expect($loan->remaining_cents)->toBe(0);
    expect($loan->status)->toBe('completed');
});

test('voiding an approved run reopens a loan and an obligation it closed', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant, ['remaining_cents' => 5000], ['outstanding_cents' => 12050]);

    $run = processBalanceRun($tenant, $user->id);
    test()->putJson(runUrl($tenant, $run, 'approve'))->assertOk();

    expect(loanOf($employee)->status)->toBe('completed');
    expect(costSharingOf($employee)->status)->toBe(CostSharingStatus::COMPLETED);

    test()->postJson(runUrl($tenant, $run, 'void'), ['reason' => 'Wrong period'])->assertOk();

    $loan = loanOf($employee);
    expect($loan->remaining_cents)->toBe(5000);
    expect($loan->status)->toBe('active');

    $obligation = costSharingOf($employee);
    expect($obligation->outstanding_cents)->toBe(12050);
    expect($obligation->status)->toBe(CostSharingStatus::ACTIVE);
    expect($obligation->completed_at)->toBeNull();
});

test('a run that fails part-way writes no entry and changes no balance', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $first = balanceEmployee($tenant);
    $second = balanceEmployee($tenant);

    $run = app(PayrollEngine::class)->begin(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    // The second employee's entry is where the worker dies: by then the
    // first employee's entry has been written and, before the fix, both
    // employees' balances had already been drawn down.
    $written = 0;
    PayrollEntry::created(function () use (&$written) {
        if (++$written === 2) {
            throw new RuntimeException('worker died');
        }
    });

    $job = new ProcessPayrollJob($run->id, $run->tenant_id);
    try {
        $job->handle(app(PayrollEngine::class));
        $this->fail('The run should have thrown.');
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect($run->fresh()->status)->toBe('failed');
    expect(PayrollEntry::where('payroll_run_id', $run->id)->count())->toBe(0);
    foreach ([$first, $second] as $employee) {
        expect(loanOf($employee)->remaining_cents)->toBe(240000);
        expect(costSharingOf($employee)->outstanding_cents)->toBe(5000000);
    }
});

test('approval is refused when a balance moved since the run was calculated', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant);

    $run = processBalanceRun($tenant, $user->id);

    // Repaid off payroll after the run was calculated: the payslip would
    // still deduct 200.00 from a loan that now owes 100.00.
    loanOf($employee)->update(['remaining_cents' => 10000]);

    test()->putJson(runUrl($tenant, $run, 'approve'))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-balances-changed');

    expect($run->fresh()->status)->toBe('completed');
    expect(loanOf($employee)->remaining_cents)->toBe(10000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(5000000);
});

test('a run calculated before balances moved on approval is approved and voided without moving them', function () {
    // Runs calculated by the old engine already drew the balances down while
    // they were computed. Their logs carry no per-loan movement, and applying
    // them again on approval would deduct twice.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = balanceEmployee($tenant, ['remaining_cents' => 220000]);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'completed']);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'other_deductions_cents' => 100000,
        'calculation_log' => [
            'version' => '1.2',
            'steps' => [
                ['step' => 'loan_deductions', 'loan_deduction_cents' => 20000],
                ['step' => 'cost_sharing', 'cost_sharing_cents' => 80000],
            ],
            'outputs' => ['loan_deduction_cents' => 20000, 'cost_sharing_cents' => 80000],
        ],
    ]);

    test()->putJson(runUrl($tenant, $run, 'approve'))->assertOk();
    expect(loanOf($employee)->remaining_cents)->toBe(220000);

    test()->postJson(runUrl($tenant, $run, 'void'), ['reason' => 'Old run'])->assertOk();
    expect(loanOf($employee)->remaining_cents)->toBe(220000);
    expect(costSharingOf($employee)->outstanding_cents)->toBe(5000000);
});
