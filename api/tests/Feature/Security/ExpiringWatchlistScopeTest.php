<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use App\Models\Tenant;

/*
 * `GET /employees/documents/expiring` and `GET /employees/contracts/expiring`
 * authorised with `Gate::authorize('employee.viewAny')` and then listed every
 * expiring document and contract in the organisation. Neither applied
 * `scopeAccessibleEmployees()`, so a role scoped to one department saw every
 * employee's work permits and contract end dates, with the employee's name and
 * public_id beside each. Both now restrict the query through the employee, as
 * `EmployeeController::index` and `AttendanceController` do. The same family
 * as `EmployeeRecordScopeTest` and `EmployeeTransitionScopeTest`, but with no
 * route-bound employee to authorise against, so the fix is the query.
 */

/**
 * A custom role scoped to one department, holding the given abilities, and a
 * login on it whose own employee sits in that department. Returns an employee
 * inside that department and one outside it.
 *
 * @param  list<string>  $abilities
 * @return array{0: Employee, 1: Employee}
 */
function watchlistScopedManager(Tenant $tenant, array $abilities): array
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

    return [
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]),
    ];
}

function expiringDocument(Employee $employee): EmployeeDocument
{
    return EmployeeDocument::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'type' => 'certificate',
        'title' => 'Work Permit',
        'file_path' => "tenants/x/employees/{$employee->public_id}/documents/permit.pdf",
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);
}

function expiringContract(Employee $employee): EmployeeContract
{
    return EmployeeContract::factory()->for($employee)->create([
        'tenant_id' => $employee->tenant_id,
        'end_date' => now()->addDays(10)->toDateString(),
    ]);
}

dataset('expiring watchlists', [
    'documents' => ['/api/v1/employees/documents/expiring', fn (Employee $e) => expiringDocument($e)],
    'contracts' => ['/api/v1/employees/contracts/expiring', fn (Employee $e) => expiringContract($e)],
]);

test('a department-scoped role sees only its own department on the watchlist', function (string $url, Closure $make) {
    $tenant = createTenant();
    [$inside, $outside] = watchlistScopedManager($tenant, ['employee.viewAny']);
    $make($inside);
    $make($outside);

    $response = $this->getJson($url)->assertOk();

    expect(collect($response->json('data'))->pluck('employee_public_id')->all())
        ->toBe([$inside->public_id]);
    expect($response->json('meta.total'))->toBe(1);
})->with('expiring watchlists');

test('a role scoped to everyone still sees every employee on the watchlist', function (string $url, Closure $make) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $first = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => Department::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);
    $second = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => Department::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);
    $make($first);
    $make($second);

    $response = $this->getJson($url)->assertOk();

    expect(collect($response->json('data'))->pluck('employee_public_id')->sort()->values()->all())
        ->toBe(collect([$first->public_id, $second->public_id])->sort()->values()->all());
})->with('expiring watchlists');
