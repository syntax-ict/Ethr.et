<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveDecisionService;

/*
 * A-Z review 2026-11-03, E-01: two approvers in the same second both passed
 * the pending check, and the days were taken from the balance twice. Each
 * case replays the race with a copy of the request read while it was still
 * pending, as the second request had.
 */

function decidedOnceSetup(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'once']);
    $balance = LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'year' => now()->year, 'entitled_days' => 20, 'pending_days' => 5, 'used_days' => 0,
    ]);
    $request = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
        'days' => 5, 'status' => LeaveStatus::PENDING,
    ]);
    $first = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $second = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    return [$tenant, $balance, $request, $first, $second];
}

it('takes the days once when two approvals race', function () {
    [, $balance, $request, $first, $second] = decidedOnceSetup();
    $stale = LeaveRequest::query()->findOrFail($request->id);

    app(LeaveDecisionService::class)->approve($request, $first);
    app(LeaveDecisionService::class)->approve($stale, $second);

    $balance->refresh();
    expect((float) $balance->used_days)->toBe(5.0)
        ->and((float) $balance->pending_days)->toBe(0.0)
        ->and($request->fresh()->status)->toBe(LeaveStatus::APPROVED);
});

it('does not reject a request another approver has just approved', function () {
    [, $balance, $request, $first, $second] = decidedOnceSetup();
    $stale = LeaveRequest::query()->findOrFail($request->id);

    app(LeaveDecisionService::class)->approve($request, $first);
    app(LeaveDecisionService::class)->reject($stale, $second, 'Too late');

    $balance->refresh();
    expect($request->fresh()->status)->toBe(LeaveStatus::APPROVED)
        ->and((float) $balance->used_days)->toBe(5.0)
        ->and((float) $balance->pending_days)->toBe(0.0);
});

it('does not cancel a request approved since the employee opened it', function () {
    [$tenant, $balance, $request, $first] = decidedOnceSetup();
    $owner = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $request->employee_id], $tenant);
    $stale = LeaveRequest::query()->findOrFail($request->id);

    app(LeaveDecisionService::class)->approve($request, $first);

    // The route binding reads the request afresh, so the late cancel is
    // replayed against the controller's own check by approving first and
    // asking for the cancel by id.
    test()->actingAs($owner)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$stale->public_id}/cancel")
        ->assertUnprocessable();

    $balance->refresh();
    expect($request->fresh()->status)->toBe(LeaveStatus::APPROVED)
        ->and((float) $balance->used_days)->toBe(5.0)
        ->and((float) $balance->pending_days)->toBe(0.0);
});
