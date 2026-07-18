<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── Super Admin Tenant Management ──

test('super admin can list tenants', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    Tenant::factory()->count(3)->create();

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
    expect($response->json('meta.total'))->toBe(4);
});

test('super admin can view tenant detail', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create(['name' => 'Acme Corp']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}");

    $response->assertOk()
        ->assertJsonPath('name', 'Acme Corp');
});

test('super admin can change tenant status', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/status", [
        'status' => 'suspended',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'suspended');

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.status_changed']);
});

test('super admin can extend trial', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => now()->addDays(7),
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/extend-trial", [
        'days' => 30,
    ]);

    $response->assertOk();
    expect($response->json('trial_ends_at'))->not->toBeNull();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.trial_extended']);
});

test('tenant admin cannot access admin endpoints', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants")
        ->assertForbidden();
});

// ── Revenue Dashboard ──

test('super admin can view revenue', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/revenue");

    $response->assertOk()
        ->assertJsonStructure([
            'mrr_cents',
            'total_tenants',
            'active_tenants',
            'trial_tenants',
            'monthly_trend',
        ]);
});

// ── System Health ──

test('super admin can view system health', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health");

    $response->assertOk()
        ->assertJsonStructure([
            'services',
            'queue',
            'failed_jobs',
        ]);
});

// ── Admin Audit Log ──

test('super admin can view platform audit log', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/audit");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
});

// ── Failed Jobs ──

test('super admin can list failed jobs', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
        'exception' => 'Exception: something broke',
        'failed_at' => now(),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0'))->not->toHaveKey('id');
});

test('tenant admin cannot list failed jobs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs")
        ->assertForbidden();
});

test('retrying an unknown failed job returns 404', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/".Str::uuid().'/retry');

    $response->assertNotFound();
});

test('retrying all failed jobs when none exist reports zero', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/retry-all");

    $response->assertOk()
        ->assertJsonPath('count', 0);
});

// ── Billing ──

test('tenant admin can view billing dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/billing/dashboard");

    $response->assertOk()
        ->assertJsonStructure(['plan', 'invoices']);
});

test('tenant admin can mark invoice as paid', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'draft',
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/billing/invoices/{$invoice->public_id}/mark-paid");

    $response->assertOk()
        ->assertJsonPath('status', 'paid');

    $invoice->refresh();
    expect($invoice->paid_at)->not->toBeNull();
});

// ── Settings ──

test('tenant admin can view settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings");

    $response->assertOk()
        ->assertJsonStructure([
            'organization',
            'attendance',
            'leave',
            'payroll',
            'security',
        ]);
});

test('tenant admin can update settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => [
            'grace_period_minutes' => 10,
            'mfa_policy' => 'required',
        ],
    ]);

    $response->assertOk();
    expect($response->json('settings.grace_period_minutes'))->toBe(10);

    $this->assertDatabaseHas('audit_log', ['action' => 'settings.updated']);
});

test('employee cannot update settings', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => ['mfa_policy' => 'required'],
    ])->assertForbidden();
});

// ── Audit Logs ──

test('tenant admin can view audit logs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
});

// ── Auth ──

test('admin endpoints require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/admin/tenants')
        ->assertUnauthorized();
});

test('billing requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/billing/dashboard')
        ->assertUnauthorized();
});

test('settings require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/settings')
        ->assertUnauthorized();
});
