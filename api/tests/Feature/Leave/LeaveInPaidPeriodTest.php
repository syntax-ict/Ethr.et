<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/*
 * A-Z review 2026-11-03, E-03: unpaid leave approved for days an approved run
 * had already paid changed no payslip, and no later run picked it up.
 */

function paidPeriodSetup(): array
{
    $tenant = createTenant();
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2024-01-01', 'status' => 'confirmed',
    ]);
    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $finance->id)->run;
    $engine->approve($run->refresh(), $finance->id);

    return [$tenant, $employee, createUser(['role' => UserRole::HR_ADMIN], $tenant)];
}

function paidPeriodLeave(Tenant $tenant, Employee $employee, bool $paid, string $start, string $end): LeaveRequest
{
    $type = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => $paid ? 'annual_x' : 'unpaid_x', 'is_paid' => $paid, 'default_days' => $paid ? 16 : 0,
    ]);

    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $start, 'end_date' => $end, 'days' => 2, 'status' => LeaveStatus::PENDING,
    ]);
}

it('refuses to approve unpaid leave for days an approved run already paid', function () {
    [$tenant, $employee, $hr] = paidPeriodSetup();
    $leave = paidPeriodLeave($tenant, $employee, false, '2026-06-15', '2026-06-16');

    test()->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed');

    expect($leave->fresh()->status)->toBe(LeaveStatus::PENDING);
});

it('still approves paid leave recorded late for a paid period, which moves no pay', function () {
    [$tenant, $employee, $hr] = paidPeriodSetup();
    $leave = paidPeriodLeave($tenant, $employee, true, '2026-06-15', '2026-06-16');

    test()->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertOk();
});

it('still approves unpaid leave in a period no run has paid', function () {
    [$tenant, $employee, $hr] = paidPeriodSetup();
    $leave = paidPeriodLeave($tenant, $employee, false, '2026-07-15', '2026-07-16');

    test()->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertOk();
});

// Known issue K-46: sick leave is a paid type, so the refusal above passed it.
// Under Art. 85 a sick day can fall at half pay or no pay, and every late day
// moves the tier of each sick day after it, so approved for a paid period it
// was never deducted, and the deductions that followed it were out by a day.
function paidPeriodSickLeave(Tenant $tenant, Employee $employee, string $start, string $end): LeaveRequest
{
    $type = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'sick_x', 'is_paid' => true, 'follows_art85' => true, 'default_days' => 30,
    ]);

    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $start, 'end_date' => $end, 'days' => 2, 'status' => LeaveStatus::PENDING,
    ]);
}

it('refuses to approve Art. 85 sick leave for days an approved run already paid', function () {
    [$tenant, $employee, $hr] = paidPeriodSetup();
    $leave = paidPeriodSickLeave($tenant, $employee, '2026-06-15', '2026-06-16');

    test()->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed')
        ->assertJsonPath('detail', __('leave.payroll_period_closed'));

    expect($leave->fresh()->status)->toBe(LeaveStatus::PENDING);
});

it('still approves Art. 85 sick leave in a period no run has paid', function () {
    [$tenant, $employee, $hr] = paidPeriodSetup();
    $leave = paidPeriodSickLeave($tenant, $employee, '2026-07-15', '2026-07-16');

    test()->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertOk();
});
