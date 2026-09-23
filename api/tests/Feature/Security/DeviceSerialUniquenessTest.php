<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.(serial_number, adapter_type)` is unique among live devices.
 *
 * `docs/audit/BASELINE.md` §11d site 1: `resolveWebhookDevice()` selects by
 * serial and adapter type with the tenant scope dropped, then `->first()`.
 * Nothing stopped two tenants holding the same serial, so which row came back
 * was undefined. §11h closes it in two independent layers, and both are pinned
 * here because either alone would be weaker than it looks:
 *
 *  - the **index** makes the duplicate unrepresentable for live devices;
 *  - the **query** treats ambiguity as a rejection rather than a coin flip, so
 *    an older database, an unrun migration or a future dropped index does not
 *    silently restore the old behaviour.
 *
 * The soft-delete cases are not incidental. `DeviceController::destroy()` soft
 * deletes and there is no restore route, so an index counting deleted rows
 * would burn a serial permanently. The index is built on a generated column
 * that goes NULL once `deleted_at` is set, and these tests are what say so.
 */
function deviceIn(Tenant $tenant, array $attributes = []): Device
{
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    return Device::factory()->zkteco()->create(array_merge([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ], $attributes));
}

// ── The index ──

test('the database refuses two live devices sharing a serial and adapter type', function () {
    $owner = createTenant();
    $other = Tenant::factory()->create();

    deviceIn($owner, ['serial_number' => 'SN-COLLIDE']);

    // Across tenants deliberately: this is the case the bypassed webhook
    // lookup cannot tell apart, because it does not state tenant_id.
    expect(fn () => deviceIn($other, ['serial_number' => 'SN-COLLIDE']))
        ->toThrow(QueryException::class);
});

test('the same serial on a different adapter type is allowed', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'serial_number' => 'SN-SHARED',
    ]);

    // The index is (serial_number, adapter_type), matching the columns the
    // webhook lookup states. Two vendors reusing a serial string is the case
    // that keeps it from being over-constrained.
    Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'serial_number' => 'SN-SHARED',
    ]);

    expect(Device::where('serial_number', 'SN-SHARED')->count())->toBe(2);
});

test('any number of devices may have no serial at all', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Device::factory()->count(3)->zkteco()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'serial_number' => null,
    ]);

    expect(Device::whereNull('serial_number')->count())->toBe(3);
});

test('deleting a device frees its serial for re-registration', function () {
    $tenant = createTenant();

    $first = deviceIn($tenant, ['serial_number' => 'SN-REUSE']);
    $first->delete();

    // The whole reason the index is built on a generated column. destroy()
    // soft deletes and there is no restore route, so an index that counted
    // deleted rows would burn this serial with no way back through the API.
    $second = deviceIn($tenant, ['serial_number' => 'SN-REUSE']);

    expect($second->exists)->toBeTrue()
        ->and(Device::withTrashed()->where('serial_number', 'SN-REUSE')->count())->toBe(2);
});

// ── The query, which does not assume the index ──

test('an ambiguous serial authenticates nobody', function () {
    $owner = createTenant();
    $other = Tenant::factory()->create();

    $ownerDevice = deviceIn($owner, [
        'serial_number' => 'SN-AMBIGUOUS',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['203.0.113.5'],
    ]);
    Employee::factory()->create(['tenant_id' => $owner->id, 'employee_code' => 'EMP-AMB']);

    // The index is dropped first, deliberately. It covers UPDATE as well as
    // INSERT, so there is no way to forge a duplicate while it exists — which
    // is the point of having it. Removing it reproduces the state this guard
    // is for: a database migrated before §11h, or one where the index was
    // later dropped. The guard must not depend on the constraint it backs up.
    Schema::table('devices', function (Blueprint $table): void {
        $table->dropUnique('devices_live_serial_unique');
    });

    deviceIn($other, [
        'serial_number' => 'SN-AMBIGUOUS',
        'adapter_type' => $ownerDevice->adapter_type,
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['203.0.113.5'],
    ]);

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->postJson("http://{$owner->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
            'sn' => 'SN-AMBIGUOUS',
            'records' => [['pin' => 'EMP-AMB', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
        ])->assertStatus(401);

    // Both tenants allowlist this IP, so before §11h whichever row ->first()
    // happened to return would have been accepted. Now neither is.
    $this->assertDatabaseMissing('attendance_records', ['source' => 'biometric']);
});

// ── The API answers 422, not a 500 from the constraint ──

test('registering a serial another tenant already holds is rejected with a validation error', function () {
    $other = Tenant::factory()->create();
    deviceIn($other, ['serial_number' => 'SN-TAKEN']);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Front Door',
        'adapter_type' => 'zkteco',
        'branch_public_id' => $branch->public_id,
        'serial_number' => 'SN-TAKEN',
        'connection_config' => ['ip' => '10.0.0.5', 'port' => 4370],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['serial_number']);
});

test('a free serial still registers', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Front Door',
        'adapter_type' => 'zkteco',
        'branch_public_id' => $branch->public_id,
        'serial_number' => 'SN-FREE',
        'connection_config' => ['ip' => '10.0.0.5', 'port' => 4370],
    ])->assertCreated();
});

test('updating a device keeps its own serial', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $device = deviceIn($tenant, ['serial_number' => 'SN-MINE']);

    // The hook excludes the row being updated; without that, renaming a device
    // would collide with itself.
    $this->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", [
        'name' => 'Back Door',
        'serial_number' => 'SN-MINE',
    ])->assertOk();
});
