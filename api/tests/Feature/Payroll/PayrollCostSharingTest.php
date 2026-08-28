<?php

declare(strict_types=1);

use App\Enums\CostSharingStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\PayrollEntry;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Cost sharing as the payroll engine sees it: the deduction has to reach net,
 * reconcile against the other-deductions bucket the payslip and accounting
 * export both read, and leave a calculation_log step that explains itself
 * (CLAUDE.md convention #11).
 *
 * A normal Gregorian month, no attendance and no loans, employees hired long
 * before the period — so gross = basic exactly and every figure below is
 * hand-checkable.
 */
function runCostSharingPayroll(int $tenantId, int $userId): PayrollEntry
{
    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

function employeeWithCostSharing(int $tenantId, array $costSharing = []): Employee
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenantId,
        'salary_cents' => 800000, // 8,000 ETB
        'hire_date' => '2020-01-01',
    ]);

    EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenantId,
        'employee_id' => $employee->id,
        'deduction_rate_percent' => 10,
        'total_obligation_cents' => 5000000,
        'outstanding_cents' => 5000000,
        ...$costSharing,
    ]);

    return $employee;
}

test('cost sharing is withheld and reduces net pay', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    employeeWithCostSharing($tenant->id);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    // 10% of an 8,000.00 gross = 800.00
    expect($entry->gross_cents)->toBe(800000);
    expect($entry->other_deductions_cents)->toBe(80000);

    // Net reconciles: gross − tax − employee pension − other deductions.
    $expectedNet = $entry->gross_cents
        - $entry->income_tax_cents
        - $entry->employee_pension_cents
        - $entry->other_deductions_cents;

    expect($entry->net_cents)->toBe($expectedNet);
});

test('the deduction is itemised so the payslip can name it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    employeeWithCostSharing($tenant->id);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    $costSharingLines = collect($entry->deductions)
        ->where('type', 'cost_sharing')
        ->values();

    expect($costSharingLines)->toHaveCount(1);
    expect($costSharingLines[0]['amount_cents'])->toBe(80000);
});

test('the calculation log records the rate and both balances', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    employeeWithCostSharing($tenant->id);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    $step = collect($entry->calculation_log['steps'])
        ->firstWhere('step', 'cost_sharing');

    expect($step)->not->toBeNull();
    expect($step['cost_sharing_cents'])->toBe(80000);
    // Without the rate and the before/after balances the figure cannot be
    // re-derived once the obligation's balance has moved on.
    expect((float) $step['rate_percent'])->toBe(10.0);
    expect($step['outstanding_before_cents'])->toBe(5000000);
    expect($step['outstanding_after_cents'])->toBe(4920000);

    expect($entry->calculation_log['outputs']['cost_sharing_cents'])->toBe(80000);
});

test('running payroll draws the balance down', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = employeeWithCostSharing($tenant->id);

    runCostSharingPayroll($tenant->id, $user->id);

    $obligation = EmployeeCostSharing::where('employee_id', $employee->id)->firstOrFail();
    expect($obligation->outstanding_cents)->toBe(4920000);
    expect($obligation->status)->toBe(CostSharingStatus::ACTIVE);
});

test('the final instalment withholds only what is owed and closes the obligation', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 10% of gross would be 800.00, but only 120.50 remains owing.
    $employee = employeeWithCostSharing($tenant->id, ['outstanding_cents' => 12050]);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    expect($entry->other_deductions_cents)->toBe(12050);

    $obligation = EmployeeCostSharing::where('employee_id', $employee->id)->firstOrFail();
    expect($obligation->outstanding_cents)->toBe(0);
    expect($obligation->status)->toBe(CostSharingStatus::COMPLETED);
    expect($obligation->completed_at)->not->toBeNull();
});

test('a suspended obligation is not withheld against', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = employeeWithCostSharing($tenant->id, [
        'status' => CostSharingStatus::SUSPENDED,
    ]);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    expect($entry->other_deductions_cents)->toBe(0);
    expect(
        EmployeeCostSharing::where('employee_id', $employee->id)->firstOrFail()->outstanding_cents,
    )->toBe(5000000);
});

test('an employee with no obligation is completely unaffected', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 800000,
        'hire_date' => '2020-01-01',
    ]);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    expect($entry->other_deductions_cents)->toBe(0);
    expect($entry->net_cents)->toBe(
        $entry->gross_cents - $entry->income_tax_cents - $entry->employee_pension_cents,
    );

    // The step is still logged, so a payslip trace has a uniform shape whether
    // or not the employee carries an obligation.
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'cost_sharing');
    expect($step['cost_sharing_cents'])->toBe(0);
    expect($step['rate_percent'])->toBeNull();
});

test('another tenant\'s obligation never reaches this payroll run', function () {
    $other = createTenant();
    $otherEmployee = Employee::factory()->create([
        'tenant_id' => $other->id,
        'salary_cents' => 800000,
        'hire_date' => '2020-01-01',
    ]);
    EmployeeCostSharing::factory()->create([
        'tenant_id' => $other->id,
        'employee_id' => $otherEmployee->id,
    ]);

    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 800000,
        'hire_date' => '2020-01-01',
    ]);

    $entry = runCostSharingPayroll($tenant->id, $user->id);

    expect($entry->other_deductions_cents)->toBe(0);
});
