<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Device\DeviceManager;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\ZktecoAdapter;

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

test('a mock device registers with a minimal non-schema connection_config', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    // Regression: keys with no explicit FormRequest rule used to be stripped by
    // validated(), storing NULL into the non-null connection_config column → 500.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Simulator',
        'adapter_type' => 'mock',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['mode' => 'simulator'],
    ])->assertStatus(201)->assertJsonPath('adapter_type', 'mock');

    $device = Device::where('tenant_id', $tenant->id)->where('name', 'Simulator')->first();
    expect($device->connection_config)->toBe(['mode' => 'simulator']);
});

test('a generic device preserves adapter-specific config keys', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Anviz Gate',
        'adapter_type' => 'generic',
        'branch_public_id' => $branch->public_id,
        'connection_config' => [
            'base_url' => 'https://anviz.local:8080',
            'mapping' => ['user_id' => 'id', 'name' => 'full_name'],
            'auth' => ['type' => 'bearer', 'token' => 'secret'],
        ],
    ])->assertStatus(201);

    $device = Device::where('tenant_id', $tenant->id)->where('name', 'Anviz Gate')->first();
    // The mapping/auth keys (no explicit rule) survive rather than being stripped.
    expect($device->connection_config['mapping']['user_id'])->toBe('id');
    expect($device->connection_config['auth']['type'])->toBe('bearer');
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

    expect($adapter)->toBeInstanceOf(HikvisionAdapter::class);
});

test('device manager returns correct adapter for zkteco', function () {
    $manager = new DeviceManager;

    $device = Device::factory()->zkteco()->make();
    $adapter = $manager->adapter($device);

    expect($adapter)->toBeInstanceOf(ZktecoAdapter::class);
});

test('device manager throws for unknown adapter type', function () {
    $manager = new DeviceManager;

    $device = Device::factory()->make(['adapter_type' => 'unknown']);

    // `expect(...)->toThrow()` rather than Pest's `->throws()` modifier. The
    // modifier asserts the exception but records no PHPUnit assertion, and
    // PHPUnit's default `beStrictAboutTestsThatDoNotTestAnything` then marks
    // the test risky -- this was the whole of "1 risky" in every run's
    // "Tests: 1 risky, 1890 passed". Same guarantee, counted.
    expect(fn () => $manager->adapter($device))
        ->toThrow(InvalidArgumentException::class);
});

// ── Webhook Endpoints ──

test('hikvision webhook processes event for known employee', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'HIK-SN-001',
        'webhook_token' => 'hik-secret-token',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'badge_number' => 'BADGE001',
    ]);

    $response = test()->withHeader('X-Webhook-Token', 'hik-secret-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
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

test('hikvision webhook rejects unknown device', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
        'AccessControllerEvent' => [
            'deviceSerialNo' => 'UNKNOWN-SN',
            'employeeNoString' => 'BADGE001',
            'time' => now()->toIso8601String(),
        ],
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('status', 'unauthorized');
});

// ── F-1: webhook authentication hardening ──

test('hikvision webhook rejects a serial-only call when the device has a token', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'HIK-SN-TOKEN',
        'webhook_token' => 'the-real-token',
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => 'BADGE-F1']);

    // No token supplied — a device that has one must not be reachable by serial.
    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
        'AccessControllerEvent' => [
            'deviceSerialNo' => 'HIK-SN-TOKEN',
            'employeeNoString' => 'BADGE-F1',
            'time' => now()->toIso8601String(),
        ],
    ]);

    $response->assertStatus(401)->assertJsonPath('status', 'unauthorized');
    $this->assertDatabaseMissing('attendance_records', ['source' => 'biometric']);
});

test('zkteco webhook allows a token-less device only from an allowlisted IP', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-LEGACY',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['203.0.113.5'],
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-LEGACY']);

    $payload = [
        'sn' => 'ZK-LEGACY',
        'records' => [['pin' => 'EMP-LEGACY', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
    ];

    // From an allowlisted source IP → accepted.
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", $payload)
        ->assertOk()
        ->assertJsonPath('status', 'processed');

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $employee->id,
        'source' => 'biometric',
    ]);
});

test('zkteco webhook rejects a token-less device from a non-allowlisted IP', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-LEGACY-2',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['203.0.113.5'],
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-LEGACY-2']);

    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
            'sn' => 'ZK-LEGACY-2',
            'records' => [['pin' => 'EMP-LEGACY-2', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
        ])
        ->assertStatus(401)
        ->assertJsonPath('status', 'unauthorized');

    $this->assertDatabaseMissing('attendance_records', ['source' => 'biometric']);
});

test('zkteco webhook rejects a token-less device with no allowlist configured', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-ORPHAN',
        'webhook_token' => null,
        'webhook_ip_allowlist' => null,
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-ORPHAN']);

    // Fail-closed: no token, no allowlist → unreachable.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
        'sn' => 'ZK-ORPHAN',
        'records' => [['pin' => 'EMP-ORPHAN', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
    ])->assertStatus(401);

    $this->assertDatabaseMissing('attendance_records', ['source' => 'biometric']);
});

test('zkteco webhook processes event for known employee', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-SN-001',
        'webhook_token' => 'zk-secret-token',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-ZK001',
    ]);

    $response = test()->withHeader('X-Webhook-Token', 'zk-secret-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
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

// ── Device Events ──

test('device events endpoint returns employee name and code', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
        'employee_code' => 'EMP-0007',
    ]);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'device_id' => $device->id,
    ]);

    // Regression: this endpoint eager-loaded non-existent `first_name`/`last_name`
    // columns on employees and returned 500 for every device-events request.
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/events");

    $response->assertOk();
    expect($response->json('data.0.employee_name'))->toBe('Abebe Kebede');
    expect($response->json('data.0.employee_code'))->toBe('EMP-0007');
});

test('employee cannot view device events', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/events")
        ->assertForbidden();
});

test('device status probe fails fast and gracefully for an unreachable device', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    // TEST-NET-1 (RFC 5737) — guaranteed unreachable. The adapter's connectTimeout must
    // bound the live probe so an offline device cannot block a worker for ~20s.
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '192.0.2.1', 'port' => 80, 'username' => 'admin', 'password' => 'x'],
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/status")
        ->assertOk()
        ->assertJsonPath('status', 'offline');

    // Time the adapter probe itself rather than the whole HTTP request: routing,
    // migrations and DB work are subject to scheduler contention under a parallel
    // run, which is unrelated to the connect-timeout budget being asserted here.
    $start = microtime(true);
    app(DeviceManager::class)->adapter($device)->getStatus($device);
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(12.0);
});

// ── Authentication ──

test('devices require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/devices')
        ->assertUnauthorized();
});
