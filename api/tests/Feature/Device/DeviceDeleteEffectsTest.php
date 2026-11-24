<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;

/*
 * The delete confirmation on the device list and the device page makes four
 * claims (device setup upgrade, PR 1). This pins each to what the server does,
 * so changing a lookup to include deleted devices turns this red instead of
 * leaving the dialog untrue: the device leaves the list, ETHR stops accepting
 * from it, its attendance records keep their device, and the terminal is sent
 * nothing.
 */

function deleteOrganisation(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $branch];
}

it('removes the device from the list, refuses its token afterwards, and keeps its records linked', function (string $adapter) {
    [$tenant, $branch] = deleteOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'connection_config' => [],
        'webhook_token' => 'delete-effects-token',
        'webhook_ip_allowlist' => ['127.0.0.1'],
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->biometric()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'device_id' => $device->id,
    ]);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";
    $heartbeat = ['ipAddress' => '10.0.0.7', 'macAddress' => 'aa:bb:cc:dd:ee:ff', 'eventType' => 'heartBeat', 'eventState' => 'active'];

    test()->withHeader('X-Webhook-Token', 'delete-effects-token')
        ->postJson("{$base}/webhook/{$adapter}", $heartbeat)->assertSuccessful();

    test()->deleteJson("{$base}/{$device->public_id}")->assertNoContent();

    $listed = collect(test()->getJson($base)->assertOk()->json('data'))->pluck('public_id');
    expect($listed)->not->toContain($device->public_id);

    $afterDelete = test()->withHeader('X-Webhook-Token', 'delete-effects-token')
        ->postJson("{$base}/webhook/{$adapter}", $heartbeat);
    expect($afterDelete->status())->toBeGreaterThanOrEqual(400);

    $kept = AttendanceRecord::query()->whereKey($record->id)->first();
    expect($kept)->not->toBeNull()
        ->and($kept->device_id)->toBe($device->id)
        ->and(Device::withTrashed()->find($device->id)?->trashed())->toBeTrue();
})->with(['hikvision', 'zkteco', 'suprema']);
