<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Employee;

// ── Show Settings ──

test('hr admin can view attendance settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings");

    $response->assertOk()
        ->assertJsonPath('geofence_required', false)
        ->assertJsonPath('kiosk_pin_required', false)
        ->assertJsonPath('qr_expiry_minutes', 30);

    // Should auto-create with defaults
    expect(AttendanceSetting::count())->toBe(1);
});

test('employee cannot view attendance settings', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings")
        ->assertForbidden();
});

// ── Update Settings ──

test('hr admin can update attendance settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    // First fetch to auto-create
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings");

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings", [
        'geofence_required' => true,
        'kiosk_pin_required' => true,
        'qr_expiry_minutes' => 60,
        'enabled_methods' => ['biometric', 'mobile', 'qr'],
    ])->assertOk()
        ->assertJsonPath('geofence_required', true)
        ->assertJsonPath('kiosk_pin_required', true)
        ->assertJsonPath('qr_expiry_minutes', 60);

    $setting = AttendanceSetting::first();
    expect($setting->geofence_required)->toBeTrue();
    expect($setting->enabled_methods)->toContain('biometric', 'mobile', 'qr');
    expect($setting->enabled_methods)->not->toContain('kiosk');
});

test('update rejects invalid enabled methods', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings");

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings", [
        'enabled_methods' => ['biometric', 'invalid_method'],
    ])->assertUnprocessable();
});

test('update rejects out of range qr expiry', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings");

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/settings", [
        'qr_expiry_minutes' => 0,
    ])->assertUnprocessable();
});

// ── Settings Isolation ──

test('attendance settings are isolated per tenant', function () {
    $tenant1 = createTenant();
    $tenant2 = createTenant();

    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant1->id,
        'geofence_required' => true,
    ]);

    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant2->id,
        'geofence_required' => false,
    ]);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant1);

    test()->getJson("http://{$tenant1->subdomain}.ethr.test/api/v1/attendance/settings")
        ->assertOk()
        ->assertJsonPath('geofence_required', true);
});
