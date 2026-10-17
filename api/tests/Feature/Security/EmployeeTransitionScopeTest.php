<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\Permission;
use App\Models\Tenant;

/*
 * `POST /employees/{employee}/transition` and `GET .../transitions` authorised
 * with `Gate::authorize('employee.transition')` and `('employee.view')`.
 * `AppServiceProvider`'s `Gate::before` answers a known Permission ability
 * from `hasPermission()` alone, so `EmployeePolicy::transition()` and `view()`
 * never ran, and neither did their `canAccessEmployee()` check: a role scoped
 * to one department could transition, and read the history of, any employee
 * in the organisation. Both now authorise against the policy with the model.
 */

/**
 * A custom role scoped to one department, holding the given abilities, and a
 * login on it whose own employee sits in that department.
 *
 * @param  list<string>  $abilities
 * @return array{0: Department, 1: Department}
 */
function transitionScopedManager(Tenant $tenant, array $abilities): array
{
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $role = CustomRole::create([
        'tenant_id' => $tenant->id,
        'name' => 'Department manager',
        'org_scope' => OrgScope::DEPARTMENT->value,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', $abilities)->pluck('id'));

    actingAsUser([
        'role' => UserRole::EMPLOYEE,
        'custom_role_id' => $role->id,
        'employee_id' => $head->id,
    ], $tenant);

    return [$mine, $theirs];
}

/** @return array<string, string> */
function transitionTermination(): array
{
    return [
        'to_status' => EmployeeStatus::TERMINATED->value,
        'effective_date' => '2026-10-31',
        'reason' => 'Out of scope attempt',
    ];
}

test('a department-scoped role cannot transition an employee outside its department', function () {
    $tenant = createTenant();
    [, $theirs] = transitionScopedManager($tenant, ['employee.transition', 'employee.view']);

    $outside = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $theirs->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    $this->postJson("/api/v1/employees/{$outside->public_id}/transition", transitionTermination())
        ->assertForbidden();

    $fresh = $outside->fresh();
    expect($fresh->status)->toBe(EmployeeStatus::CONFIRMED)
        ->and($fresh->termination_date)->toBeNull()
        ->and(EmployeeTransition::where('employee_id', $outside->id)->exists())->toBeFalse();
});

test('a department-scoped role still transitions an employee inside its department', function () {
    $tenant = createTenant();
    [$mine] = transitionScopedManager($tenant, ['employee.transition', 'employee.view']);

    $inside = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $mine->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    $this->postJson("/api/v1/employees/{$inside->public_id}/transition", transitionTermination())
        ->assertCreated()
        ->assertJsonPath('to_status', EmployeeStatus::TERMINATED->value);

    expect($inside->fresh()->status)->toBe(EmployeeStatus::TERMINATED)
        ->and(EmployeeTransition::where('employee_id', $inside->id)->count())->toBe(1);
});

test('a department-scoped role cannot read the transition history of an employee outside its department', function () {
    $tenant = createTenant();
    [$mine, $theirs] = transitionScopedManager($tenant, ['employee.view']);

    $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);
    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $this->getJson("/api/v1/employees/{$outside->public_id}/transitions")->assertForbidden();
    $this->getJson("/api/v1/employees/{$inside->public_id}/transitions")->assertOk();
});

test('the permission is still required inside the scope', function () {
    $tenant = createTenant();
    [$mine] = transitionScopedManager($tenant, ['employee.view']);

    $inside = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $mine->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    $this->postJson("/api/v1/employees/{$inside->public_id}/transition", transitionTermination())
        ->assertForbidden();

    expect(EmployeeTransition::where('employee_id', $inside->id)->exists())->toBeFalse();
});

test('an HR admin transitions and reads any employee in the organisation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $anyone = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => Department::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    $this->postJson("/api/v1/employees/{$anyone->public_id}/transition", transitionTermination())
        ->assertCreated();
    $this->getJson("/api/v1/employees/{$anyone->public_id}/transitions")
        ->assertOk()
        ->assertJsonCount(1);

    expect($anyone->fresh()->status)->toBe(EmployeeStatus::TERMINATED);
});
