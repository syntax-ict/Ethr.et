<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Webhook;

// ── Cross-Tenant Isolation ──

test('tenant A cannot see tenant B employees', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    Employee::factory()->count(3)->create(['tenant_id' => $tenantA->id]);
    Employee::factory()->count(5)->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/employees');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

test('tenant A cannot see tenant B attendance', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    $empB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
    AttendanceRecord::factory()->count(3)->create([
        'tenant_id' => $tenantB->id,
        'employee_id' => $empB->id,
    ]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/attendance');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('tenant A cannot see tenant B leave types', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    LeaveType::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'annual']);
    LeaveType::factory()->sick()->create(['tenant_id' => $tenantB->id]);
    LeaveType::factory()->maternity()->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/leave-types');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('tenant A cannot see tenant B payroll runs', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    PayrollRun::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/payroll/runs');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('tenant A cannot see tenant B holidays', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    Holiday::factory()->count(2)->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/holidays');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('tenant A cannot see tenant B announcements', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    $userB = \App\Models\User::factory()->create(['tenant_id' => $tenantB->id, 'role' => UserRole::HR_ADMIN]);
    Announcement::factory()->count(2)->create([
        'tenant_id' => $tenantB->id,
        'published_by' => $userB->id,
    ]);

    $empA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
    $userA = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $empA->id], $tenantA);
    test()->actingAs($userA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/announcements');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('tenant A cannot see tenant B webhooks', function () {
    $tenantA = createTenant(['subdomain' => 'alpha']);
    $tenantB = createTenant(['subdomain' => 'beta']);

    Webhook::factory()->count(2)->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantA);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/webhooks');

    $response->assertOk();
    expect($response->json('webhooks'))->toHaveCount(0);
});

// ── Role Authorization Matrix ──

test('employee cannot access hr endpoints', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees")
        ->assertForbidden();
});

test('employee cannot access payroll endpoints', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs")
        ->assertForbidden();
});

test('employee cannot access executive dashboard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive")
        ->assertForbidden();
});

test('employee cannot access reports', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/sources")
        ->assertForbidden();
});

test('hr admin cannot access admin console', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants")
        ->assertForbidden();
});

// ── API Never Leaks Internal IDs ──

test('employee response never exposes numeric id', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees");

    $response->assertOk();
    $first = $response->json('data.0');
    expect($first)->toHaveKey('public_id');
    expect($first)->not->toHaveKey('id');
    expect($first)->not->toHaveKey('tenant_id');
});

test('payroll response never exposes numeric id', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    PayrollRun::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs");

    $response->assertOk()
        ->assertJsonMissingPath('data.0.id');
});

// ── Unauthenticated Access ──

test('all protected endpoints return 401 without auth', function () {
    $tenant = createTenant(['subdomain' => 'sectest']);

    $endpoints = [
        'GET' => [
            '/api/v1/employees',
            '/api/v1/attendance',
            '/api/v1/payroll/runs',
            '/api/v1/dashboard/employee',
            '/api/v1/directory',
            '/api/v1/notifications',
            '/api/v1/settings',
        ],
    ];

    foreach ($endpoints['GET'] as $path) {
        test()->getJson("http://sectest.ethr.test{$path}")
            ->assertUnauthorized();
    }
});
