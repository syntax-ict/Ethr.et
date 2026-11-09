<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Notifications\LeaveChangedNotification;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\LeaveYear;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * A-Z review 2026-11-03, E-02 (U-E1): approved leave could not be undone by
 * anyone. HR now cancels it, or ends it early, with a reason; the days it no
 * longer takes go back to the balance, and the employee is told.
 */

function recallSetup(string $start = '2026-12-07', string $end = '2026-12-11'): array
{
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2024-01-01', 'status' => 'confirmed']);
    $person = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual_r', 'is_paid' => true, 'default_days' => 16]);

    $days = recallDays($employee, $start, $end);
    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $start, 'end_date' => $end, 'days' => $days, 'status' => LeaveStatus::APPROVED,
    ]);
    $balance = LeaveBalance::query()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => LeaveYear::forTenantId($tenant->id)->yearOf(Carbon::parse($start)),
        'entitled_days' => 16, 'used_days' => $days, 'carried_days' => 0, 'pending_days' => 0,
    ]);

    return [$tenant, $hr, $employee, $person, $leave, $balance];
}

function recallDays(Employee $employee, string $start, string $end): float
{
    return app(LeaveDayCalculator::class)->calculateDays(Carbon::parse($start), Carbon::parse($end), $employee->tenant_id, $employee->branch_id, $employee);
}

function recallUrl(Tenant $tenant, LeaveRequest $leave): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/recall";
}

it('ends approved leave early and gives the days back', function () {
    Notification::fake();
    [$tenant, $hr, $employee, $person, $leave, $balance] = recallSetup();
    $kept = recallDays($employee, '2026-12-07', '2026-12-08');

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), ['end_date' => '2026-12-08', 'reason' => 'Came back early'])
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('end_date', '2026-12-08');

    $leave->refresh();
    expect($leave->status)->toBe(LeaveStatus::APPROVED)
        ->and($leave->end_date->format('Y-m-d'))->toBe('2026-12-08')
        ->and((float) $leave->days)->toBe($kept)
        ->and((float) $balance->fresh()->used_days)->toBe($kept);

    expect(AuditLog::query()->where('action', 'leave.recalled')->count())->toBe(1);
    Notification::assertSentTo($person, LeaveChangedNotification::class);
});

it('cancels approved leave and gives every day back', function () {
    Notification::fake();
    [$tenant, $hr, , $person, $leave, $balance] = recallSetup();

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), ['reason' => 'Trip called off'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect($leave->fresh()->status)->toBe(LeaveStatus::CANCELLED)
        ->and((float) $balance->fresh()->used_days)->toBe(0.0);
    Notification::assertSentTo($person, LeaveChangedNotification::class);
});

it('changes only approved leave', function () {
    [$tenant, $hr, , , $leave] = recallSetup();
    $leave->update(['status' => LeaveStatus::PENDING]);

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), ['reason' => 'x'])
        ->assertUnprocessable();
});

it('does not let HR change their own leave', function () {
    [$tenant, $hr, $employee, $person, $leave] = recallSetup();
    // HR is the person on leave: their one login is the HR account.
    $person->delete();
    $hr->update(['employee_id' => $employee->id]);

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), ['reason' => 'x'])
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe(LeaveStatus::APPROVED);
});

it('refuses to free days in a payroll period that is already approved', function () {
    [$tenant, $hr, $employee, , $leave, $balance] = recallSetup('2026-06-15', '2026-06-19');
    $employee->update(['salary_cents' => 500000]);
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $finance->id)->run;
    $engine->approve($run->refresh(), $finance->id);
    $used = (float) $balance->fresh()->used_days;

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), ['end_date' => '2026-06-16', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed');

    expect($leave->fresh()->end_date->format('Y-m-d'))->toBe('2026-06-19')
        ->and((float) $balance->fresh()->used_days)->toBe($used);
});

it('is for whoever administers leave balances, not every approver', function () {
    [$tenant, , , , $leave] = recallSetup();
    $supervisor = createUser(['role' => UserRole::SUPERVISOR], $tenant);

    test()->actingAs($supervisor)
        ->putJson(recallUrl($tenant, $leave), ['reason' => 'x'])
        ->assertForbidden();
});

it('needs a reason and an end date inside the leave', function (array $body) {
    [$tenant, $hr, , , $leave] = recallSetup();

    test()->actingAs($hr)
        ->putJson(recallUrl($tenant, $leave), $body)
        ->assertUnprocessable();

    expect($leave->fresh()->end_date->format('Y-m-d'))->toBe('2026-12-11');
})->with([
    'no reason' => [['end_date' => '2026-12-08']],
    'end date not earlier' => [['end_date' => '2026-12-11', 'reason' => 'x']],
    'end date before the start' => [['end_date' => '2026-12-06', 'reason' => 'x']],
]);

it('does not reach another organisation\'s leave', function () {
    [, , , , $leave] = recallSetup();
    $other = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $other);

    test()->actingAs($hr)
        ->putJson(recallUrl($other, $leave), ['reason' => 'x'])
        ->assertNotFound();

    expect($leave->fresh()->status)->toBe(LeaveStatus::APPROVED);
});
