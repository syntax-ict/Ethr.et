<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\CurrentTenant;

/**
 * The header's global search (2026-10-19). What is pinned is the promise that
 * makes it safe: search never shows a row the caller could not open from its
 * own page, in this tenant or another.
 */
function searchAs(Tenant $tenant, string $q)
{
    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/search?q=".urlencode($q));
}

test('an HR admin finds people by name or code, with their attendance, leave and department', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Logistics']);
    $abebe = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
        'employee_code' => 'EMP-0042',
        'department_id' => $department->id,
    ]);
    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual leave']);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $abebe->id,
        'leave_type_id' => $annual->id,
    ]);

    searchAs($tenant, 'Abebe')
        ->assertOk()
        ->assertJsonPath('employees.0.id', $abebe->public_id)
        ->assertJsonPath('employees.0.department', 'Abebe Logistics')
        ->assertJsonPath('attendance.0.id', $abebe->public_id)
        ->assertJsonPath('leave.0.employee_name', 'Abebe Kebede')
        ->assertJsonPath('leave.0.leave_type', 'Annual leave')
        ->assertJsonPath('departments.0.name', 'Abebe Logistics')
        ->assertJsonMissingPath('employees.0.public_id');

    searchAs($tenant, 'EMP-0042')->assertJsonPath('employees.0.id', $abebe->public_id);
});

test('a supervisor finds only the people they can open', function () {
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lead Person']);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tigist Report', 'supervisor_id' => $lead->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tigist Elsewhere']);

    $ids = collect(searchAs($tenant, 'Tigist')->assertOk()->json('employees'))->pluck('id');

    expect($ids->all())->toBe([$report->public_id]);
});

test('an employee finds no colleagues and only their own leave', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Selam Mine']);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Selam Other']);
    $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sick leave']);
    $mine = LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $me->id, 'leave_type_id' => $type->id]);
    LeaveRequest::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $other->id, 'leave_type_id' => $type->id]);

    $response = searchAs($tenant, 'Selam')->assertOk();

    expect($response->json('employees'))->toBe([])
        ->and($response->json('attendance'))->toBe([])
        ->and($response->json('departments'))->toBe([])
        ->and(collect($response->json('leave'))->pluck('id')->all())->toBe([$mine->public_id])
        ->and($response->json('leave.0.own'))->toBeTrue();
});

test('search never reaches another organisation', function () {
    $tenant = createTenant();
    $other = Tenant::factory()->create();
    Employee::factory()->create(['tenant_id' => $other->id, 'name' => 'Hidden Outsider']);
    Department::factory()->create(['tenant_id' => $other->id, 'name' => 'Hidden Unit']);
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = searchAs($tenant, 'Hidden')->assertOk();

    expect($response->json('employees'))->toBe([])
        ->and($response->json('departments'))->toBe([]);
});

test('a wildcard in the query is matched literally where the query is escaped', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

    expect(searchAs($tenant, '%%')->assertOk()->json('departments'))->toBe([]);
});

test('the query must be two to a hundred characters', function (string $q) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    searchAs($tenant, $q)->assertStatus(422)->assertJsonValidationErrors(['q']);
})->with([
    'one character' => ['a'],
    'too long' => [str_repeat('a', 101)],
]);

test('search needs a signed-in user', function () {
    $tenant = createTenant();

    searchAs($tenant, 'Abebe')->assertUnauthorized();
});
