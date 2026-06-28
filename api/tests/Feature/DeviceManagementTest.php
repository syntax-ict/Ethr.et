<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Device\DeviceManager;

// ── Device CRUD ──

test('tenant admin can list devices', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->count(3)->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('hr admin can list devices', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->count(2)->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('employee cannot list devices', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices")
        ->assertForbidden();
});

test('tenant admin can register a device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Main Entrance Terminal',
        'adapter_type' => 'hikvision',
        'branch_public_id' => $branch->public_id,
        'serial_number' => 'SN-12345678',
        'connection_config' => [
            'ip' => '192.168.1.100',
            'port' => 80,
            'username' => 'admin',
            'password' => 'pass123',
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Main Entrance Terminal')
        ->assertJsonPath('adapter_type', 'hikvision')
        ->assertJsonPath('status', 'pending')
        ->assertJsonMissingPath('id');
});

test('hr admin cannot register a device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Test',
        'adapter_type' => 'hikvision',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['ip' => '1.2.3.4', 'port' => 80],
    ])->assertForbidden();
});

test('can view single device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $device->public_id)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('connection_config');
});

test('tenant admin can update a device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'Old Name',
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", [
        'name' => 'Updated Name',
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'Updated Name');
});

test('tenant admin can delete a device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}")
        ->assertNoContent();

    expect(Device::find($device->id))->toBeNull();
});

test('device creation requires valid adapter type', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Test',
        'adapter_type' => 'invalid_type',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['ip' => '1.2.3.4', 'port' => 80],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['adapter_type']);
});

test('device creation requires connection config', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Test',
        'adapter_type' => 'hikvision',
        'branch_public_id' => $branch->public_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['connection_config']);
});

// ── Device Search & Filter ──

test('can search devices by name', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main Entrance']);
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Back Door']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices?search=Main");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('can filter devices by status', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'online']);
    Device::factory()->offline()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices?filter[status]=offline");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('can filter devices by adapter type', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->hikvision()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    Device::factory()->hikvision()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    Device::factory()->zkteco()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices?filter[adapter_type]=hikvision");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Device Dashboard ──

test('hr admin can view device dashboard', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'online']);
    Device::factory()->offline()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'pending']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/dashboard");

    $response->assertOk()
        ->assertJsonStructure(['total', 'online', 'offline', 'error', 'pending', 'events_today'])
        ->assertJsonPath('total', 3)
        ->assertJsonPath('online', 1)
        ->assertJsonPath('offline', 1)
        ->assertJsonPath('pending', 1);
});

// ── Device Manager ──

test('device manager returns correct adapter for hikvision', function () {
    $manager = new DeviceManager;

    $device = Device::factory()->hikvision()->make();
    $adapter = $manager->adapter($device);

    expect($adapter)->toBeInstanceOf(\App\Services\Device\HikvisionAdapter::class);
});

test('device manager returns correct adapter for zkteco', function () {
    $manager = new DeviceManager;

    $device = Device::factory()->zkteco()->make();
    $adapter = $manager->adapter($device);

    expect($adapter)->toBeInstanceOf(\App\Services\Device\ZktecoAdapter::class);
});

test('device manager throws for unknown adapter type', function () {
    $manager = new DeviceManager;

    $device = Device::factory()->make(['adapter_type' => 'unknown']);
    $manager->adapter($device);
})->throws(\InvalidArgumentException::class);

// ── Webhook Endpoints ──

test('hikvision webhook processes event for known employee', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'HIK-SN-001',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'badge_number' => 'BADGE001',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
        'AccessControllerEvent' => [
            'deviceSerialNo' => 'HIK-SN-001',
            'employeeNoString' => 'BADGE001',
            'time' => now()->toIso8601String(),
            'eventType' => 1,
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'processed');

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $employee->id,
        'source' => 'biometric',
    ]);
});

test('hikvision webhook ignores unknown device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
        'AccessControllerEvent' => [
            'deviceSerialNo' => 'UNKNOWN-SN',
            'employeeNoString' => 'BADGE001',
            'time' => now()->toIso8601String(),
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'device_not_found');
});

test('zkteco webhook processes event for known employee', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-SN-001',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-ZK001',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
        'sn' => 'ZK-SN-001',
        'records' => [
            [
                'pin' => 'EMP-ZK001',
                'timestamp' => now()->toIso8601String(),
                'punch' => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'processed')
        ->assertJsonPath('count', 1);

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $employee->id,
        'source' => 'biometric',
    ]);
});

// ── Device Audit Logging ──

test('device creation is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Audit Test Device',
        'adapter_type' => 'hikvision',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['ip' => '1.2.3.4', 'port' => 80],
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'device.created',
    ]);
});

// ── Tenant Isolation ──

test('devices are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    $branch1 = Branch::factory()->create(['tenant_id' => $tenant1->id]);
    $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id]);

    Device::factory()->count(2)->create(['tenant_id' => $tenant1->id, 'branch_id' => $branch1->id]);
    Device::factory()->count(3)->create(['tenant_id' => $tenant2->id, 'branch_id' => $branch2->id]);

    $user1 = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant1);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/devices');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Authentication ──

test('devices require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/devices')
        ->assertUnauthorized();
});
