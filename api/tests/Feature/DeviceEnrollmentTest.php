<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Device\GenericHttpAdapter;
use Illuminate\Support\Facades\Http;

describe('device enrollment discovery endpoint', function () {
    it('lists enrolled users with a suggested employee match', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $matched = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-1',
            'badge_number' => '1001',
            'name' => 'Abebe Kebede',
        ]);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/enrollments");

        $response->assertOk()
            ->assertJsonPath('device.public_id', $device->public_id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.matched', 1)
            ->assertJsonPath('enrollments.0.device_user_id', '1001')
            ->assertJsonPath('enrollments.0.match.outcome', 'matched')
            ->assertJsonPath('enrollments.0.match.employee_public_id', $matched->public_id);
    });

    it('flags an unknown enrolled user as new', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        // An employee exists so the mock has someone to enroll, but its identifiers
        // won't be searched for anyone else — this one enrollment resolves to itself.
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'ONLY-1', 'badge_number' => 'B1']);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'mock',
            'status' => 'online',
        ]);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/enrollments")
            ->assertOk()
            ->assertJsonPath('summary.matched', 1);
    });

    it('denies enrollment discovery to employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'mock']);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/enrollments")
            ->assertForbidden();
    });
});

describe('GenericHttpAdapter', function () {
    it('maps a configured JSON enrollments endpoint to the canonical shape', function () {
        $tenant = createTenant();
        $device = Device::factory()->make([
            'tenant_id' => $tenant->id,
            'adapter_type' => 'generic',
            'connection_config' => [
                'base_url' => 'https://middleware.local',
                'auth' => ['type' => 'bearer', 'token' => 'secret'],
                'enrollments_path' => '/api/staff',
                'mapping' => [
                    'user_id' => 'id',
                    'name' => 'full_name',
                    'card' => 'rfid',
                    'enrollments_root' => 'data',
                ],
            ],
        ]);

        Http::fake([
            'middleware.local/api/staff*' => Http::response([
                'data' => [
                    ['id' => '77', 'full_name' => 'Mulu Alem', 'rfid' => 'CARD-77'],
                    ['id' => '78', 'full_name' => 'Sara Bekele', 'rfid' => 'CARD-78'],
                ],
            ]),
        ]);

        $enrollments = app(GenericHttpAdapter::class)->pullEnrollments($device);

        expect($enrollments)->toHaveCount(2);
        expect($enrollments[0])->toMatchArray([
            'device_user_id' => '77',
            'name' => 'Mulu Alem',
            'card_number' => 'CARD-77',
        ]);
    });

    it('maps a configured JSON events endpoint and infers punch direction', function () {
        $tenant = createTenant();
        $device = Device::factory()->make([
            'tenant_id' => $tenant->id,
            'adapter_type' => 'generic',
            'connection_config' => [
                'base_url' => 'https://mw.local',
                'events_path' => '/logs',
                'mapping' => ['badge' => 'uid', 'timestamp' => 'at', 'type' => 'dir'],
                'check_in_values' => ['in'],
            ],
        ]);

        Http::fake([
            'mw.local/logs*' => Http::response([
                ['uid' => '5', 'at' => '2026-07-30T08:00:00', 'dir' => 'in'],
                ['uid' => '5', 'at' => '2026-07-30T17:00:00', 'dir' => 'out'],
            ]),
        ]);

        $events = app(GenericHttpAdapter::class)->pullEvents($device);

        expect($events)->toHaveCount(2);
        expect($events[0]['type'])->toBe('check_in');
        expect($events[1]['type'])->toBe('check_out');
    });

    it('returns an empty list when the device responds with an error', function () {
        $tenant = createTenant();
        $device = Device::factory()->make([
            'tenant_id' => $tenant->id,
            'adapter_type' => 'generic',
            'connection_config' => ['base_url' => 'https://down.local'],
        ]);

        Http::fake(['down.local/*' => Http::response(null, 500)]);

        expect(app(GenericHttpAdapter::class)->pullEnrollments($device))->toBe([]);
    });
});

it('accepts the generic adapter type when registering a device', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Anviz Front Gate',
        'branch_public_id' => $branch->public_id,
        'adapter_type' => 'generic',
        'connection_config' => ['base_url' => 'https://anviz.local:8080'],
    ])->assertCreated()->assertJsonPath('adapter_type', 'generic');
});
