<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;

/**
 * GET /navigation/badges: the two counts the sidebar and the tab bar draw
 * (settings review F11, PR 13). The sidebar used to fetch the whole manager
 * dashboard for them. Each count must equal the one the page behind its row
 * shows, so these tests compare against those endpoints for the same data.
 */
function badgesGet(Tenant $tenant, User $user, string $path = 'navigation/badges')
{
    app('auth')->forgetGuards();
    test()->actingAs($user);

    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/{$path}");
}

function badgesPendingLeave(Tenant $tenant, Employee $employee): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'L'.uniqid()])->id,
        'status' => LeaveStatus::PENDING,
    ]);
}

function badgesFlag(Tenant $tenant, Employee $employee, string $status = 'open'): AttendanceIntegrityFlag
{
    $record = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

    return AttendanceIntegrityFlag::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'attendance_record_id' => $record->id,
        'punch' => 'check_in',
        'type' => 'outside_geofence',
        'severity' => 'high',
        'status' => $status,
    ]);
}

/**
 * One organisation with two departments, each holding a person with a pending
 * leave request and an open integrity flag, plus a pending profile change and
 * an already-decided flag that nothing may count.
 *
 * @return array{tenant: Tenant, deptX: Department, deptY: Department, inX: Employee, inY: Employee}
 */
function badgesOrganisation(): array
{
    $tenant = createTenant();
    $deptX = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => null]);
    $deptY = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => null]);
    $inX = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptX->id]);
    $inY = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptY->id]);

    badgesPendingLeave($tenant, $inX);
    badgesPendingLeave($tenant, $inY);
    badgesFlag($tenant, $inX);
    badgesFlag($tenant, $inY);
    badgesFlag($tenant, $inY, 'accepted');
    ProfileUpdateRequest::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $inY->id,
        'field_name' => 'phone',
        'old_value' => '+251911000000',
        'new_value' => '+251911111111',
        'status' => ProfileUpdateStatus::PENDING,
    ]);

    return compact('tenant', 'deptX', 'deptY', 'inX', 'inY');
}

test('the counts equal the manager dashboard and the integrity queue for the same data', function () {
    ['tenant' => $tenant] = badgesOrganisation();
    $hr = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id], $tenant);

    $dashboard = badgesGet($tenant, $hr, 'dashboard/manager')->assertOk()->json('pending_approvals.total');
    $integrity = badgesGet($tenant, $hr, 'attendance/integrity/summary')->assertOk()->json('open');
    $badges = badgesGet($tenant, $hr)->assertOk();

    expect($dashboard)->toBe(3)
        ->and($integrity)->toBe(2)
        ->and($badges->json('approvals'))->toBe($dashboard)
        ->and($badges->json('attendance_integrity'))->toBe($integrity);
    $badges->assertJsonCount(2);
});

test('a department-scoped approver sees the same scoped counts the dashboard gives', function () {
    ['tenant' => $tenant, 'deptX' => $deptX] = badgesOrganisation();
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptX->id]);
    $user = createScopedUser($tenant, $head->id, 'department', ['leave.viewTeam', 'leave.approve', 'attendance.viewConflicts']);

    $dashboard = badgesGet($tenant, $user, 'dashboard/manager')->assertOk()->json('pending_approvals.total');
    $integrity = badgesGet($tenant, $user, 'attendance/integrity/summary')->assertOk()->json('open');
    $badges = badgesGet($tenant, $user)->assertOk();

    expect($dashboard)->toBe(1)
        ->and($integrity)->toBe(1)
        ->and($badges->json('approvals'))->toBe(1)
        ->and($badges->json('attendance_integrity'))->toBe(1);
});

test('a count the caller may not see is null, whichever of the two it is', function () {
    ['tenant' => $tenant, 'deptX' => $deptX] = badgesOrganisation();
    $someone = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptX->id]);

    $approverOnly = createScopedUser($tenant, $someone->id, 'department', ['leave.viewTeam', 'leave.approve']);
    badgesGet($tenant, $approverOnly)->assertOk()
        ->assertJsonPath('approvals', 1)
        ->assertJsonPath('attendance_integrity', null);

    $anotherInX = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptX->id]);
    $reviewerOnly = createScopedUser($tenant, $anotherInX->id, 'department', ['attendance.viewConflicts']);
    badgesGet($tenant, $reviewerOnly)->assertOk()
        ->assertJsonPath('approvals', null)
        ->assertJsonPath('attendance_integrity', 1);
});

test('an employee gets a 200 with nothing they may not see', function () {
    ['tenant' => $tenant, 'inX' => $inX] = badgesOrganisation();
    $employee = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $inX->id], $tenant);

    badgesGet($tenant, $employee)->assertOk()
        ->assertExactJson(['approvals' => null, 'attendance_integrity' => null]);
});

test('another organisation is counted from its own rows only', function () {
    ['tenant' => $a] = badgesOrganisation();
    $hrA = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => Employee::factory()->create(['tenant_id' => $a->id])->id], $a);

    $b = createTenant();
    $inB = Employee::factory()->create(['tenant_id' => $b->id]);
    badgesPendingLeave($b, $inB);
    badgesFlag($b, $inB);
    $hrB = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => Employee::factory()->create(['tenant_id' => $b->id])->id], $b);

    badgesGet($b, $hrB)->assertOk()
        ->assertJsonPath('approvals', 1)
        ->assertJsonPath('attendance_integrity', 1);
    badgesGet($a, $hrA)->assertOk()
        ->assertJsonPath('approvals', 3)
        ->assertJsonPath('attendance_integrity', 2);
});

test('a request with no sign-in is refused', function () {
    $tenant = createTenant();
    app('auth')->forgetGuards();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/navigation/badges")->assertUnauthorized();
});
