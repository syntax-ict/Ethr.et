<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Quarantine: list, replay and dismiss. Replaying puts a punch back through the
 * inbox, so a condition fixed afterwards (a leaving date, a terminal clock) lets
 * it record, once.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function quarantineApiFixture(array $employee = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 17:30:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'connection_config' => ['mode' => 'push'],
        'webhook_token' => 'qapi-token',
    ]);
    $person = Employee::factory()->create($employee + ['tenant_id' => $tenant->id, 'badge_number' => '1001']);

    return [$tenant, $device, $person];
}

function quarantineApiPush(Tenant $tenant, string $badge, string $time): void
{
    test()->withHeader('X-Webhook-Token', 'qapi-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
            'dateTime' => "2026-10-14T{$time}+03:00",
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 75, 'employeeNoString' => $badge, 'attendanceStatus' => 'checkIn'],
        ])->assertOk();
}

function quarantineApi(Tenant $tenant, string $method, string $path, array $body = []): TestResponse
{
    return test()->json($method, "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$path}", $body);
}

it('lists a quarantined punch with its raw event for someone who may change the device', function () {
    [$tenant, $device] = quarantineFixtureLeaver();

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    quarantineApi($tenant, 'GET', "{$device->public_id}/quarantine")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $row->public_id)
        ->assertJsonPath('data.0.error', $row->error)
        ->assertJsonPath('data.0.badge', '1001')
        ->assertJsonPath('data.0.failure', 'refused:employee_has_left')
        ->assertJsonMissingPath('data.0.id')
        ->assertJson(fn ($json) => $json->where('data.0.raw', fn ($raw) => str_contains((string) $raw, '1001'))->etc());
});

it('lists for a view-only user but does not show raw, and refuses replay and dismiss', function () {
    [$tenant, $device] = quarantineFixtureLeaver();
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    quarantineApi($tenant, 'GET', "{$device->public_id}/quarantine")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.raw', null)
        ->assertJsonPath('data.0.error', null);

    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])->assertForbidden();
    quarantineApi($tenant, 'POST', 'quarantine/dismiss', ['ids' => [$row->public_id]])->assertForbidden();

    expect($row->refresh()->failed_at)->not->toBeNull()->and($row->dismissed_at)->toBeNull();
});

it('records a leaver punch once when replayed after the leaving date is corrected', function () {
    [$tenant, $device, $employee] = quarantineFixtureLeaver();
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    $employee->update(['termination_date' => '2026-12-31']);

    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])
        ->assertOk()->assertJsonPath('replayed', 1);

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($row->refresh()->failed_at)->toBeNull()
        ->and($row->raw)->toBeNull();

    // Put the same row back in quarantine and replay again: a duplicate, not a second record.
    $row->forceFill(['failed_at' => now(), 'failure' => 'error', 'processed_at' => now()])->save();
    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])->assertOk();

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'device.quarantine_replayed')->count())->toBe(2);
});

it('records a punch from a clock that ran ahead once the time has passed', function () {
    [$tenant, $device, $employee] = quarantineApiPlain();
    quarantineApiPush($tenant, '1001', '23:30:00');

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->failure)->toBe('clock_ahead');

    Carbon::setTestNow(Carbon::parse('2026-10-14 23:45:00', 'Africa/Addis_Ababa'));

    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])
        ->assertOk()->assertJsonPath('replayed', 1);

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('dismisses a quarantined punch, which leaves the list and the counts', function () {
    [$tenant, $device] = quarantineFixtureLeaver();
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    quarantineApi($tenant, 'GET', 'dashboard')->assertOk()->assertJsonPath('quarantined.punches', 1)
        ->assertJsonPath('quarantined.devices.0.device_public_id', $device->public_id);
    quarantineApi($tenant, 'GET', (string) $device->public_id)->assertOk()->assertJsonPath('quarantined_count', 1);

    quarantineApi($tenant, 'POST', 'quarantine/dismiss', ['ids' => [$row->public_id]])
        ->assertOk()->assertJsonPath('dismissed', 1);

    quarantineApi($tenant, 'GET', "{$device->public_id}/quarantine")->assertOk()->assertJsonCount(0, 'data');
    quarantineApi($tenant, 'GET', 'dashboard')->assertOk()->assertJsonPath('quarantined.punches', 0);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'device.quarantine_dismissed')->count())->toBe(1);
});

it('validates the list of ids', function () {
    [$tenant, $device] = quarantineApiPlain();

    quarantineApi($tenant, 'POST', 'quarantine/replay', [])->assertUnprocessable();
    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => array_fill(0, 501, str_repeat('A', 26))])->assertUnprocessable();
    quarantineApi($tenant, 'GET', "{$device->public_id}/quarantine?per_page=500")->assertOk()->assertJsonPath('per_page', 100);
});

it('shows another organisation nothing, and lets it change nothing', function () {
    [$tenantA, $deviceA] = quarantineFixtureLeaver();
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    $tenantB = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantB);

    quarantineApi($tenantB, 'GET', "{$deviceA->public_id}/quarantine")->assertNotFound();
    quarantineApi($tenantB, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])->assertOk()->assertJsonPath('replayed', 0);
    quarantineApi($tenantB, 'POST', 'quarantine/dismiss', ['ids' => [$row->public_id]])->assertOk()->assertJsonPath('dismissed', 0);

    expect($row->refresh()->failed_at)->not->toBeNull()->and($row->dismissed_at)->toBeNull();
});

it('replays a failure that was kept before this change, which has no raw copy', function () {
    [$tenant, $device, $employee] = quarantineApiPlain();

    // What the migration makes of an old processed row with an error.
    DB::table('device_inbound_punches')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'device_id' => $device->id,
        'badge' => '1001', 'punch_type' => 'check_in', 'occurred_raw' => '2026-10-14T08:00:00+03:00',
        'idempotency_key' => 'webhook:hik:legacy:1001', 'processed_at' => now(), 'failed_at' => now(),
        'failure' => 'error', 'error' => 'Something old.', 'raw' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])
        ->assertOk()->assertJsonPath('replayed', 1);

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($row->refresh()->failed_at)->toBeNull();
});

it('does not replay a line it cannot read, and keeps what the terminal sent', function () {
    [$tenant, $device] = quarantineApiPlain();

    DB::table('device_inbound_punches')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'device_id' => $device->id,
        'badge' => '', 'punch_type' => 'auto', 'occurred_raw' => '', 'idempotency_key' => 'webhook:adms:bad:1',
        'processed_at' => now(), 'failed_at' => now(), 'failure' => 'unreadable', 'error' => 'The line could not be read.',
        'raw' => 'garbage without tabs', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    quarantineApi($tenant, 'POST', 'quarantine/replay', ['ids' => [$row->public_id]])
        ->assertOk()->assertJsonPath('replayed', 0)->assertJsonPath('skipped', 1);

    $row->refresh();
    expect($row->failed_at)->not->toBeNull()
        ->and($row->raw)->toBe('garbage without tabs')
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('names the devices and rows in the audit entries', function () {
    [$tenant, $device] = quarantineFixtureLeaver();
    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();

    quarantineApi($tenant, 'POST', 'quarantine/dismiss', ['ids' => [$row->public_id]])->assertOk();

    $entry = AuditLog::withoutGlobalScopes()->where('action', 'device.quarantine_dismissed')->sole();
    expect($entry->payload['devices'] ?? null)->toBe([$device->public_id])
        ->and($entry->payload['rows'] ?? null)->toBe([$row->public_id]);
});

/** @return array{0: Tenant, 1: Device, 2: Employee} a leaver whose punch is already in quarantine */
function quarantineFixtureLeaver(): array
{
    $fixture = quarantineApiFixture(['status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-09-01']);
    quarantineApiPush($fixture[0], '1001', '08:00:00');

    return $fixture;
}

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function quarantineApiPlain(): array
{
    return quarantineApiFixture();
}
