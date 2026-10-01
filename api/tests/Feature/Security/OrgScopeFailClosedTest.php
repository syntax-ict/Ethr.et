<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;

/**
 * scopeAccessibleEmployees() passed a null anchor straight to where(), which
 * Laravel turns into `IS NULL` — so an org-scoped login missing its anchor
 * (no employee record, no department, no team) reached every employee missing
 * the same thing, instead of no one. Found while scoping the correction queue
 * (audit N3), which relies on this scope.
 */
test('a supervisor login with no employee record reaches no one, not every unsupervised employee', function () {
    $tenant = createTenant();
    $unsupervised = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => null], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);

    expect($query->pluck('id')->all())->toBe([]);
    expect($user->canAccessEmployee($unsupervised))->toBeFalse();
});

test('a supervisor login with no employee record sees no pending corrections', function () {
    $tenant = createTenant();
    $unsupervised = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    $record = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $unsupervised->id]);
    AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $unsupervised->id,
        'status' => CorrectionStatus::PENDING,
    ]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => null], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/pending")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a department admin outside any department reaches no one', function () {
    $tenant = createTenant();
    $admin = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null]);
    $noDepartment = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null]);
    $inDepartment = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => Department::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);
    $user = createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $admin->id], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);

    expect($query->pluck('id')->all())->toBe([]);
    expect($user->canAccessEmployee($noDepartment))->toBeFalse();
    expect($user->canAccessEmployee($inDepartment))->toBeFalse();
});

test('a team-scoped role outside any team reaches no one', function () {
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'team_id' => null]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'team_id' => null]);

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Team Lead', 'org_scope' => 'team']);
    $role->permissions()->sync(Permission::whereIn('name', ['employee.viewAny'])->pluck('id'));
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $lead->id, 'custom_role_id' => $role->id], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);

    expect($query->pluck('id')->all())->toBe([]);
});
