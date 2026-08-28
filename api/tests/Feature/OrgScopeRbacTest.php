<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;

// ──────────────────────────── OrgScope enum ────────────────────────────

test('OrgScope::fromRole maps all roles correctly', function () {
    expect(OrgScope::fromRole(UserRole::SUPER_ADMIN))->toBe(OrgScope::ALL);
    expect(OrgScope::fromRole(UserRole::TENANT_ADMIN))->toBe(OrgScope::ALL);
    expect(OrgScope::fromRole(UserRole::HR_ADMIN))->toBe(OrgScope::ALL);
    expect(OrgScope::fromRole(UserRole::FINANCE_ADMIN))->toBe(OrgScope::ALL);
    expect(OrgScope::fromRole(UserRole::DEPT_ADMIN))->toBe(OrgScope::DEPARTMENT);
    expect(OrgScope::fromRole(UserRole::SUPERVISOR))->toBe(OrgScope::DIRECT_REPORTS);
    expect(OrgScope::fromRole(UserRole::EMPLOYEE))->toBe(OrgScope::SELF);
});

// ──────────────── canAccessEmployee — per-role scope ──────────────────

test('tenant admin can access any employee in tenant', function () {
    $tenant = createTenant();

    $empA = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $empB = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::TENANT_ADMIN,
        'employee_id' => $empA->id,
    ], $tenant);

    expect($user->orgScope())->toBe(OrgScope::ALL);
    expect($user->canAccessEmployee($empA))->toBeTrue();
    expect($user->canAccessEmployee($empB))->toBeTrue();
});

test('hr admin can access any employee in tenant', function () {
    $tenant = createTenant();

    $empA = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $empB = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::HR_ADMIN,
        'employee_id' => $empA->id,
    ], $tenant);

    expect($user->orgScope())->toBe(OrgScope::ALL);
    expect($user->canAccessEmployee($empB))->toBeTrue();
});

test('dept admin can only access employees in their department', function () {
    $tenant = createTenant();

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

    $empSelf = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empSameDept = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empOtherDept = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    $user = createUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => $empSelf->id,
    ], $tenant);

    expect($user->orgScope())->toBe(OrgScope::DEPARTMENT);
    expect($user->canAccessEmployee($empSelf))->toBeTrue();
    expect($user->canAccessEmployee($empSameDept))->toBeTrue();
    expect($user->canAccessEmployee($empOtherDept))->toBeFalse();
});

test('supervisor can only access direct reports and self', function () {
    $tenant = createTenant();

    $empSupervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $empReport = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $empSupervisor->id,
    ]);
    $empOther = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::SUPERVISOR,
        'employee_id' => $empSupervisor->id,
    ], $tenant);

    expect($user->orgScope())->toBe(OrgScope::DIRECT_REPORTS);
    expect($user->canAccessEmployee($empSupervisor))->toBeTrue();
    expect($user->canAccessEmployee($empReport))->toBeTrue();
    expect($user->canAccessEmployee($empOther))->toBeFalse();
});

test('employee can only access self', function () {
    $tenant = createTenant();

    $empSelf = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $empOther = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $empSelf->id,
    ], $tenant);

    expect($user->orgScope())->toBe(OrgScope::SELF);
    expect($user->canAccessEmployee($empSelf))->toBeTrue();
    expect($user->canAccessEmployee($empOther))->toBeFalse();
});

// ──────────────── scopeAccessibleEmployees — query filtering ──────────

test('scopeAccessibleEmployees returns all for admin', function () {
    $tenant = createTenant();

    $emp1 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $emp2 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $emp3 = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::TENANT_ADMIN,
        'employee_id' => $emp1->id,
    ], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($emp1->id)
        ->toContain($emp2->id)
        ->toContain($emp3->id);
});

test('scopeAccessibleEmployees filters for dept admin', function () {
    $tenant = createTenant();

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

    $empA = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empA2 = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empB = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    $user = createUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => $empA->id,
    ], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($empA->id)
        ->toContain($empA2->id)
        ->not->toContain($empB->id);
});

test('scopeAccessibleEmployees filters for supervisor', function () {
    $tenant = createTenant();

    $empSup = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $empReport = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $empSup->id,
    ]);
    $empOther = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser([
        'role' => UserRole::SUPERVISOR,
        'employee_id' => $empSup->id,
    ], $tenant);

    $query = Employee::query();
    $user->scopeAccessibleEmployees($query);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($empSup->id)
        ->toContain($empReport->id)
        ->not->toContain($empOther->id);
});

// ──────────────── Policy integration ──────────────────────────────────

test('dept admin employee policy allows same-dept, blocks cross-dept', function () {
    $tenant = createTenant();

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

    $empAdmin = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empSameDept = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    $empOtherDept = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    $user = actingAsUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => $empAdmin->id,
    ], $tenant);

    $response = $this->getJson("/api/v1/employees/{$empSameDept->public_id}");
    $response->assertOk();

    $response = $this->getJson("/api/v1/employees/{$empOtherDept->public_id}");
    $response->assertForbidden();
});

test('employee listing is scoped to department for dept admin', function () {
    $tenant = createTenant();

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

    $empAdmin = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    actingAsUser([
        'role' => UserRole::DEPT_ADMIN,
        'employee_id' => $empAdmin->id,
    ], $tenant);

    $response = $this->getJson('/api/v1/employees');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

test('tenant admin listing returns all employees', function () {
    $tenant = createTenant();

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

    $empAdmin = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    actingAsUser([
        'role' => UserRole::TENANT_ADMIN,
        'employee_id' => $empAdmin->id,
    ], $tenant);

    $response = $this->getJson('/api/v1/employees');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(6);
});
