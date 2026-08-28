<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// Salary 500,000 cents over June 2026; WORKING_DAYS_PER_MONTH = 22, so the
// contractual daily rate is round(500000/22) = 22,727 cents.
function makeLeaveEmployee(int $tenantId): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenantId,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);
}

function runJunePayroll(int $tenantId, int $userId): PayrollEntry
{
    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

function unpaidLeaveStep(PayrollEntry $entry): array
{
    return collect($entry->calculation_log['steps'])->firstWhere('step', 'unpaid_leave');
}

test('approved unpaid leave reduces earned basic salary', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = makeLeaveEmployee($tenant->id);

    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'unpaid', 'is_paid' => false,
    ]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $unpaid->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'days' => 3,
    ]);

    $entry = runJunePayroll($tenant->id, $user->id);

    // 500,000 - round(22,727 * 3) = 500,000 - 68,181 = 431,819.
    expect($entry->basic_salary_cents)->toBe(431819);
    expect($entry->gross_cents)->toBe(431819);

    $step = unpaidLeaveStep($entry);
    expect($step['unpaid_leave_days'])->toEqual(3.0);
    expect($step['daily_rate_cents'])->toBe(22727);
    expect($step['deduction_cents'])->toBe(68181);
});

test('paid leave does not reduce pay', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = makeLeaveEmployee($tenant->id);

    $paid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'annual', 'is_paid' => true,
    ]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $paid->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'days' => 3,
    ]);

    $entry = runJunePayroll($tenant->id, $user->id);

    expect($entry->basic_salary_cents)->toBe(500000);
    expect(unpaidLeaveStep($entry)['deduction_cents'])->toBe(0);
});

test('only approved unpaid leave is deducted, not pending', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = makeLeaveEmployee($tenant->id);

    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'unpaid', 'is_paid' => false,
    ]);
    LeaveRequest::factory()->create([ // pending by default
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $unpaid->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'days' => 3,
    ]);

    $entry = runJunePayroll($tenant->id, $user->id);

    expect($entry->basic_salary_cents)->toBe(500000);
    expect(unpaidLeaveStep($entry)['unpaid_leave_days'])->toEqual(0.0);
});

test('unpaid leave spanning the period boundary is clipped to the period', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = makeLeaveEmployee($tenant->id);

    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'unpaid', 'is_paid' => false,
    ]);
    // Leave 2026-05-28 .. 2026-06-03 — only Jun 1 (Mon), 2 (Tue), 3 (Wed) fall in June.
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $unpaid->id,
        'start_date' => '2026-05-28',
        'end_date' => '2026-06-03',
        'days' => 5,
    ]);

    $entry = runJunePayroll($tenant->id, $user->id);

    $step = unpaidLeaveStep($entry);
    expect($step['unpaid_leave_days'])->toEqual(3.0);       // clipped working days in June
    expect($entry->basic_salary_cents)->toBe(431819);    // 500,000 - 3 * 22,727
});

test('half-day unpaid leave deducts a half day', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = makeLeaveEmployee($tenant->id);

    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'unpaid', 'is_paid' => false,
    ]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $unpaid->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-10',
        'days' => 0.5,
    ]);

    $entry = runJunePayroll($tenant->id, $user->id);

    // round(22,727 * 0.5) = 11,364 -> 500,000 - 11,364 = 488,636.
    expect(unpaidLeaveStep($entry)['unpaid_leave_days'])->toEqual(0.5);
    expect($entry->basic_salary_cents)->toBe(488636);
});

test('no leave leaves basic salary unchanged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    makeLeaveEmployee($tenant->id);

    $entry = runJunePayroll($tenant->id, $user->id);

    expect($entry->basic_salary_cents)->toBe(500000);
    expect(unpaidLeaveStep($entry)['deduction_cents'])->toBe(0);
});
