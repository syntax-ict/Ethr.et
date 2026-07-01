<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveType;

// ── Employee Dashboard ──

test('employee can access dashboard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/employee");

    $response->assertOk()
        ->assertJsonStructure([
            'attendance_today',
            'leave_balances',
            'latest_payslip',
            'upcoming_holidays',
        ]);
});

test('dashboard shows attendance status', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
        'check_in' => now()->subHours(3)->format('H:i:s'),
        'check_out' => null,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/employee");

    $response->assertOk();
    expect($response->json('attendance_today.status'))->toBe('checked_in');
});

test('dashboard shows leave balances', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual']);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'used_days' => 5,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/employee");

    $response->assertOk();
    expect($response->json('leave_balances'))->toHaveCount(1);
    expect((float) $response->json('leave_balances.0.remaining'))->toBe(15.0);
});

test('dashboard shows upcoming holidays', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Meskel',
        'date' => now()->addDays(10),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/employee");

    $response->assertOk();
    expect($response->json('upcoming_holidays'))->toHaveCount(1);
    expect($response->json('upcoming_holidays.0.name'))->toBe('Meskel');
});

// ── Directory ──

test('authenticated user can access directory', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory");

    $response->assertOk()
        ->assertJsonCount(4, 'data');
});

test('directory does not expose sensitive fields', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory");

    $response->assertOk()
        ->assertJsonMissingPath('data.0.salary_cents')
        ->assertJsonMissingPath('data.0.id');
});

test('directory supports search', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Chala Deressa',
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/directory?search=Abebe");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Abebe Kebede');
});

// ── Announcements ──

test('employee can list published announcements', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Announcement::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    Announcement::factory()->draft()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('expired announcements are not listed', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Announcement::factory()->expired()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements");

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

test('hr admin can create announcement', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'Office Closed Friday',
        'body' => 'The office will be closed this Friday for a national holiday.',
        'priority' => 'high',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('title', 'Office Closed Friday')
        ->assertJsonPath('priority', 'high')
        ->assertJsonMissingPath('id');
});

test('employee cannot create announcement', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'Test',
        'body' => 'Body text',
    ])->assertForbidden();
});

test('hr admin can update announcement', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$announcement->public_id}", [
        'title' => 'Updated Title',
    ]);

    $response->assertOk()
        ->assertJsonPath('title', 'Updated Title');
});

test('hr admin can delete announcement', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$announcement->public_id}")
        ->assertNoContent();
});

test('announcement creation is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'Important',
        'body' => 'Details here',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'announcement.created',
    ]);
});

// ── Authentication ──

test('dashboard requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/dashboard/employee')
        ->assertUnauthorized();
});

test('directory requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/directory')
        ->assertUnauthorized();
});
