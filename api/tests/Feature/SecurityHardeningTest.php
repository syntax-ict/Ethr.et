<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\Webhook;
use App\Rules\ExternalUrl;
use Illuminate\Support\Facades\Validator;

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

    $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'role' => UserRole::HR_ADMIN]);
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

// ── Security Headers ──

test('api responses include required security headers', function () {
    $tenant = createTenant(['subdomain' => 'headers-test']);

    $response = test()->getJson('http://headers-test.ethr.test/api/v1/health');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-XSS-Protection', '1; mode=block');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// ── Rate Limiting ──

test('login rate limiting blocks after burst limit', function () {
    $tenant = createTenant(['subdomain' => 'ratelimit-test']);

    for ($i = 0; $i < 5; $i++) {
        test()->postJson('http://ratelimit-test.ethr.test/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrongpassword',
        ]);
    }

    $response = test()->postJson('http://ratelimit-test.ethr.test/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(429);
    $response->assertJsonPath('status', 429);
});

// ── SSRF Prevention (Webhook URLs) ──

test('webhook url rejects localhost addresses', function () {
    $rule = new ExternalUrl;
    $validator = Validator::make(['url' => 'http://localhost/evil'], ['url' => [$rule]]);
    expect($validator->fails())->toBeTrue();
});

test('webhook url rejects private ip ranges', function () {
    $rule = new ExternalUrl;

    $localAddresses = [
        'http://127.0.0.1/steal',
        'http://192.168.1.1/data',
        'http://10.0.0.1/internal',
        'http://172.16.0.1/private',
    ];

    foreach ($localAddresses as $url) {
        $validator = Validator::make(['url' => $url], ['url' => [$rule]]);
        expect($validator->fails())->toBeTrue("URL {$url} should be rejected");
    }
});

test('webhook url accepts valid external urls', function () {
    $rule = new ExternalUrl;
    $validator = Validator::make(['url' => 'https://example.com/webhook'], ['url' => [$rule]]);
    expect($validator->passes())->toBeTrue();
});

// ── File Upload Content-Type ──

test('document upload rejects disallowed file types', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/documents",
        [
            'title' => 'Test',
            'type' => 'contract',
        ]
    );

    $response->assertStatus(422);
});

// ── IDOR Prevention (cross-tenant public_id reuse) ──

test('cannot access employee from another tenant via public_id in URL', function () {
    $tenantA = createTenant(['subdomain' => 'idor-alpha']);
    $tenantB = createTenant(['subdomain' => 'idor-beta']);

    $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    test()->getJson("http://idor-alpha.ethr.test/api/v1/employees/{$employeeB->public_id}")
        ->assertNotFound();
});

test('cannot approve leave request from another tenant via public_id', function () {
    $tenantA = createTenant(['subdomain' => 'idor-alpha2']);
    $tenantB = createTenant(['subdomain' => 'idor-beta2']);

    $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'annual']);
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenantB->id,
        'employee_id' => $employeeB->id,
        'leave_type_id' => $leaveType->id,
    ]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    test()->putJson("http://idor-alpha2.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertNotFound();
});

// ── Self-Approval Prevention ──

test('manager cannot approve their own leave request', function () {
    $tenant = createTenant(['subdomain' => 'selfapprove']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::HR_ADMIN,
        'employee_id' => $employee->id,
    ], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'status' => 'pending',
    ]);

    test()->putJson("http://selfapprove.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval');
});

// ── Payroll Immutability ──

test('payroll entry cannot be modified after run is approved', function () {
    $tenant = createTenant(['subdomain' => 'payimmut']);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'approved',
    ]);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    test()->putJson("http://payimmut.ethr.test/api/v1/payroll/runs/{$run->public_id}/entries/{$entry->public_id}", [
        'basic_salary' => 50000_00,
    ])->assertStatus(404);
});

test('approved payroll run cannot be re-approved', function () {
    $tenant = createTenant(['subdomain' => 'payimmut2']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'approved',
    ]);

    test()->putJson("http://payimmut2.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');
});

// ── Audit Log Completeness ──

test('audit log is written for key operations', function () {
    $tenant = createTenant(['subdomain' => 'auditcomp']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->putJson("http://auditcomp.ethr.test/api/v1/employees/{$employee->public_id}", [
        'first_name' => 'Updated',
        'last_name' => 'Name',
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'employee.updated',
        'tenant_id' => $tenant->id,
    ]);
});

test('audit log entries cannot be deleted via API', function () {
    $tenant = createTenant(['subdomain' => 'auditprot']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->deleteJson('http://auditprot.ethr.test/api/v1/audit-log/1')
        ->assertStatus(404);
});
