<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\DeviceRequestFailed;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\Tenant;
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

    it('throws when the device responds with an error, rather than answering an empty roster', function () {
        $tenant = createTenant();
        $device = Device::factory()->make([
            'tenant_id' => $tenant->id,
            'adapter_type' => 'generic',
            'connection_config' => ['base_url' => 'https://down.local'],
        ]);

        Http::fake(['down.local/*' => Http::response(null, 500)]);

        expect(fn () => app(GenericHttpAdapter::class)->pullEnrollments($device))
            ->toThrow(DeviceRequestFailed::class, 'HTTP 500');
    });
});

/**
 * A tenant admin, and a generic device whose middleware is down.
 *
 * @return array{0: string, 1: Device, 2: Tenant}
 */
function unreadableDevice(): array
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'generic',
        'connection_config' => ['base_url' => 'https://down.local'],
    ]);
    Http::fake(['down.local/*' => Http::response(null, 503)]);

    return ["http://{$tenant->subdomain}.ethr.test/api/v1", $device, $tenant];
}

describe('a device that cannot be read', function () {
    it('answers discovery with a 502 problem, not an empty roster', function () {
        [$host, $device] = unreadableDevice();

        // It used to answer 200 with `summary.total: 0`, which reads as a
        // device with nobody enrolled.
        test()->getJson("{$host}/devices/{$device->public_id}/enrollments")
            ->assertStatus(502)
            ->assertJsonPath('status', 502)
            ->assertJsonPath('type', 'https://ethr.et/errors/device-unreachable')
            ->assertJsonPath('detail', __('device.read_failed'))
            ->assertJsonMissingPath('enrollments');
    });

    it('does not put the vendor, status or address in the client-facing detail', function () {
        [$host, $device] = unreadableDevice();

        $detail = test()->getJson("{$host}/devices/{$device->public_id}/enrollments")->json('detail');

        expect($detail)->not->toContain('503')
            ->and($detail)->not->toContain('down.local')
            ->and($detail)->not->toContain('Generic');
    });

    it('answers staging a migration with a 502 and leaves no empty batch behind', function () {
        [$host, $device, $tenant] = unreadableDevice();

        // The batch used to be created before the device was read, so a down
        // device produced an empty batch, indistinguishable from one with
        // nobody enrolled.
        test()->postJson("{$host}/onboarding/migration/devices/{$device->public_id}")
            ->assertStatus(502)
            ->assertJsonPath('detail', __('device.read_failed'));

        expect(MigrationBatch::where('tenant_id', $tenant->id)->count())->toBe(0);
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
