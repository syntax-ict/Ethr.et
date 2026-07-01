<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// ── Admin CRUD ──

test('hr admin can list kiosk sessions', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    KioskSession::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('employee cannot list kiosk sessions', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs(User::where('employee_id', $employee->id)->first());

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions")
        ->assertForbidden();
});

test('hr admin can register a kiosk', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions", [
        'name' => 'Lobby Kiosk',
        'branch_public_id' => $branch->public_id,
        'admin_pin' => '1234',
        'device_identifier' => 'tablet-lobby-01',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Lobby Kiosk')
        ->assertJsonPath('status', 'active')
        ->assertJsonMissingPath('id');

    expect($response->json('token'))->not->toBeNull();
    expect(KioskSession::count())->toBe(1);
});

test('kiosk registration requires valid branch', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions", [
        'name' => 'Test',
        'branch_public_id' => 'nonexistent',
        'admin_pin' => '1234',
    ])->assertUnprocessable();
});

test('hr admin can deactivate and activate a kiosk', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions/{$session->public_id}/deactivate")
        ->assertOk()
        ->assertJsonPath('status', 'deactivated');

    expect($session->fresh()->status)->toBe('inactive');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions/{$session->public_id}/activate")
        ->assertOk()
        ->assertJsonPath('status', 'activated');

    expect($session->fresh()->status)->toBe('active');
});

test('hr admin can regenerate kiosk token', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $oldToken = $session->token;

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions/{$session->public_id}/regenerate-token");

    $response->assertOk();
    expect($response->json('token'))->not->toBe($oldToken);
});

test('hr admin can delete a kiosk', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions/{$session->public_id}")
        ->assertNoContent();

    expect(KioskSession::count())->toBe(0);
});

// ── Kiosk Authentication ──

test('kiosk authenticate succeeds with valid token', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/authenticate", [
        'token' => $session->token,
    ]);

    $response->assertOk()
        ->assertJsonPath('tenant.name', $tenant->name)
        ->assertJsonPath('tenant.subdomain', $tenant->subdomain);
});

test('kiosk authenticate rejects invalid token', function () {
    $tenant = createTenant();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/authenticate", [
        'token' => 'fake-token-123',
    ])->assertStatus(401);
});

test('kiosk authenticate rejects inactive session', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'inactive',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/authenticate", [
        'token' => $session->token,
    ])->assertStatus(401);
});

// ── Kiosk Check-in ──

test('kiosk check-in succeeds with valid token and employee code', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-0001',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-0001',
        'type' => 'check_in',
        'idempotency_key' => 'kiosk-test-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token]);

    $response->assertStatus(201)
        ->assertJsonPath('employee_name', $employee->name);
});

test('kiosk check-in rejects missing token', function () {
    $tenant = createTenant();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-0001',
        'type' => 'check_in',
        'idempotency_key' => 'kiosk-test-'.uniqid(),
    ])->assertStatus(401);
});

test('kiosk check-in rejects unknown employee code', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'NONEXISTENT',
        'type' => 'check_in',
        'idempotency_key' => 'kiosk-test-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token])
        ->assertStatus(404);
});

test('kiosk check-in enforces PIN when required', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant->id,
        'kiosk_pin_required' => true,
    ]);

    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-PIN1',
        'kiosk_pin' => Hash::make('5678'),
    ]);

    // Missing PIN
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-PIN1',
        'type' => 'check_in',
        'idempotency_key' => 'kiosk-pin-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token])
        ->assertStatus(422);

    // Wrong PIN
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-PIN1',
        'type' => 'check_in',
        'pin' => '9999',
        'idempotency_key' => 'kiosk-pin2-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token])
        ->assertStatus(401);

    // Correct PIN
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-PIN1',
        'type' => 'check_in',
        'pin' => '5678',
        'idempotency_key' => 'kiosk-pin3-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token])
        ->assertStatus(201);
});

test('kiosk check-in rejects disabled kiosk method', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant->id,
        'enabled_methods' => ['biometric', 'mobile'],
    ]);

    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-NOKIOSK',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-NOKIOSK',
        'type' => 'check_in',
        'idempotency_key' => 'kiosk-disabled-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token])
        ->assertStatus(403);
});

// ── Tenant Isolation ──

test('kiosk sessions are isolated per tenant', function () {
    $tenant1 = createTenant();
    $tenant2 = createTenant();

    $branch1 = Branch::factory()->create(['tenant_id' => $tenant1->id]);
    $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id]);

    KioskSession::factory()->count(2)->create(['tenant_id' => $tenant1->id, 'branch_id' => $branch1->id]);
    KioskSession::factory()->count(3)->create(['tenant_id' => $tenant2->id, 'branch_id' => $branch2->id]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant1);

    test()->getJson("http://{$tenant1->subdomain}.ethr.test/api/v1/kiosk-sessions")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
