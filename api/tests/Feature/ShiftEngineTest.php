<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\ShiftMatcher;
use Carbon\Carbon;

// ── Shift CRUD ──

test('hr admin can list shifts', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Shift::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('hr admin can create a shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts", [
        'name' => 'Morning Shift',
        'start_time' => '08:30',
        'end_time' => '17:30',
        'grace_minutes' => 15,
        'break_minutes' => 60,
        'working_days' => '1,2,3,4,5',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Morning Shift')
        ->assertJsonPath('start_time', '08:30')
        ->assertJsonMissingPath('id');
});

test('employee cannot create a shift', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts", [
        'name' => 'Shift',
        'start_time' => '08:30',
        'end_time' => '17:30',
    ])->assertForbidden();
});

test('hr admin can update a shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/{$shift->public_id}", [
        'name' => 'Updated Shift',
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'Updated Shift');
});

test('tenant admin can delete a shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/{$shift->public_id}")
        ->assertNoContent();

    expect(Shift::find($shift->id))->toBeNull();
});

test('hr admin cannot delete a shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/{$shift->public_id}")
        ->assertForbidden();
});

test('can view single shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/{$shift->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $shift->public_id)
        ->assertJsonMissingPath('id');
});

test('shift search by name', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning Shift']);
    Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Night Shift']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts?search=Morning");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('setting is_default clears other defaults', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $first = Shift::factory()->create(['tenant_id' => $tenant->id, 'is_default' => true]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts", [
        'name' => 'New Default',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'is_default' => true,
    ])->assertStatus(201);

    $first->refresh();
    expect($first->is_default)->toBeFalse();
});

// ── Shift Assignment ──

test('hr admin can assign shift to employee', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/assign", [
        'shift_public_id' => $shift->public_id,
        'assignable_type' => 'employee',
        'assignable_public_id' => $employee->public_id,
        'effective_from' => '2026-01-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('assignable_type', 'Employee');
});

test('hr admin can assign shift to department', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $dept = Department::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/assign", [
        'shift_public_id' => $shift->public_id,
        'assignable_type' => 'department',
        'assignable_public_id' => $dept->public_id,
        'effective_from' => '2026-01-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('assignable_type', 'Department');
});

test('hr admin can assign shift to branch', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/assign", [
        'shift_public_id' => $shift->public_id,
        'assignable_type' => 'branch',
        'assignable_public_id' => $branch->public_id,
        'effective_from' => '2026-01-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('assignable_type', 'Branch');
});

// ── Shift Matching Priority ──

test('shift matcher prioritizes employee over department over branch', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'department_id' => $dept->id,
    ]);

    $branchShift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Branch Shift']);
    $deptShift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dept Shift']);
    $empShift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Employee Shift']);

    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $branchShift->id,
        'assignable_type' => Branch::class,
        'assignable_id' => $branch->id,
    ]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $deptShift->id,
        'assignable_type' => Department::class,
        'assignable_id' => $dept->id,
    ]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $empShift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
    ]);

    $matcher = new ShiftMatcher;
    $matched = $matcher->match($employee, Carbon::today());

    expect($matched->name)->toBe('Employee Shift');
});

test('shift matcher falls back to department when no employee assignment', function () {
    $tenant = createTenant();
    $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $dept->id,
    ]);

    $deptShift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dept Shift']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $deptShift->id,
        'assignable_type' => Department::class,
        'assignable_id' => $dept->id,
    ]);

    $matcher = new ShiftMatcher;
    $matched = $matcher->match($employee, Carbon::today());

    expect($matched->name)->toBe('Dept Shift');
});

test('shift matcher falls back to default shift', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $defaultShift = Shift::factory()->default()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Default Shift',
    ]);

    $matcher = new ShiftMatcher;
    $matched = $matcher->match($employee, Carbon::today());

    expect($matched->name)->toBe('Default Shift');
});

test('shift matcher returns null when no shift found', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $matcher = new ShiftMatcher;
    $matched = $matcher->match($employee, Carbon::today());

    expect($matched)->toBeNull();
});

// ── Night Shift (Crosses Midnight) ──

test('night shift crosses midnight correctly', function () {
    $shift = Shift::factory()->nightShift()->make();

    expect($shift->crosses_midnight)->toBeTrue();
    expect($shift->start_time)->toBe('22:00');
    expect($shift->end_time)->toBe('06:00');
});

// ── Shift Schedule Endpoint ──

test('can view shift schedule', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/schedule");

    $response->assertOk();
});

// ── Shift Validation ──

test('shift requires name and times', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'start_time', 'end_time']);
});

// ── Tenant Isolation ──

test('shifts are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    Shift::factory()->count(2)->create(['tenant_id' => $tenant1->id]);
    Shift::factory()->count(3)->create(['tenant_id' => $tenant2->id]);

    $user1 = createUser(['role' => UserRole::HR_ADMIN], $tenant1);
    test()->actingAs($user1);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/shifts');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Audit Logging ──

test('shift creation is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts", [
        'name' => 'Logged Shift',
        'start_time' => '08:00',
        'end_time' => '17:00',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'shift.created',
    ]);
});

test('shift assignment is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shifts/assign", [
        'shift_public_id' => $shift->public_id,
        'assignable_type' => 'employee',
        'assignable_public_id' => $employee->public_id,
        'effective_from' => '2026-01-01',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'shift.assigned',
    ]);
});

// ── Requires Authentication ──

test('shifts require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/shifts')
        ->assertUnauthorized();
});
