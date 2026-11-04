<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\TenantTime;

/**
 * "Your department" on the dashboard (dashboard review, 2026-11-03, PR 4).
 *
 * A department admin lost the organisation tiles in #561 and never had direct
 * reports, so the page told them "No direct reports". This section counts
 * exactly the people `scopeAccessibleEmployees()` reaches: the department and
 * the units under it, never a sibling department.
 */
function departmentOrg(Tenant $tenant): array
{
    $deptA = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => null, 'name' => 'Finance', 'name_am' => 'ፋይናንስ']);
    $deptA1 = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $deptA->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => null]);

    $in = fn (Department $d) => Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $d->id]);

    return [
        'deptA' => $deptA,
        'headA' => $in($deptA),
        'staffA' => $in($deptA),
        'staffA1' => $in($deptA1),
        'staffB' => $in($deptB),
    ];
}

test('a department admin reads their department and the units under it, never a sibling', function () {
    $tenant = createTenant();
    $org = departmentOrg($tenant);
    test()->actingAs(createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $org['headA']->id], $tenant));

    // Today is the tenant's day, not the server's: around midnight UTC the
    // two differ, and a record dated by now() is yesterday's in Addis Ababa.
    $today = TenantTime::today($tenant)->format('Y-m-d');
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $org['staffA']->id,
        'date' => $today,
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $org['staffB']->id,
        'date' => $today,
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $org['staffA1']->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => LeaveStatus::APPROVED,
        'start_date' => now()->subDay()->format('Y-m-d'),
        'end_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/department")
        ->assertOk()
        ->assertJsonPath('scope', 'department')
        ->assertJsonPath('unit.name', 'Finance')
        ->assertJsonPath('unit.name_am', 'ፋይናንስ')
        ->assertJsonPath('headcount', 3)
        ->assertJsonPath('attendance.present', 1)
        ->assertJsonPath('on_leave_today', 1);

    expect($response->json('attendance.absent'))->toBeInt()
        ->and($response->json('pending_approvals'))->toBeInt();
});

test('a team-scoped role reads its own team', function () {
    $tenant = createTenant();
    $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
    $teamA = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'name' => 'Collections']);
    $teamB = Team::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamA->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamA->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'team_id' => $teamB->id]);
    test()->actingAs(createScopedUser($tenant, $lead->id, 'team', ['attendance.viewTeam']));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/department")
        ->assertOk()
        ->assertJsonPath('scope', 'team')
        ->assertJsonPath('unit.name', 'Collections')
        ->assertJsonPath('headcount', 2);
});

test('a department admin whose record has no department counts nobody', function () {
    $tenant = createTenant();
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null]);
    test()->actingAs(createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $head->id], $tenant));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/department")
        ->assertOk()
        ->assertJsonPath('unit', null)
        ->assertJsonPath('headcount', 0)
        ->assertJsonPath('on_leave_today', 0);
});

test('the section is closed to roles that reach the organisation or only their reports', function (UserRole $role) {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => $role, 'employee_id' => $employee->id], $tenant));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/department")
        ->assertForbidden();
})->with([UserRole::HR_ADMIN, UserRole::SUPERVISOR, UserRole::EMPLOYEE]);

test('/auth/me says how far the role reaches', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $employee->id], $tenant));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")
        ->assertOk()
        ->assertJsonPath('user.org_scope', 'department');
});
