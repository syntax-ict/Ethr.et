<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessDeviceInboundPunchesJob;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceCaptureRedactor;
use App\Services\Device\DeviceManager;
use App\Services\Device\HikvisionEvent;
use App\Services\Device\WebhookRejections;
use App\Support\HealthSignals;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Device health that tells the truth (BASELINE 25ab): one rule for every
 * terminal that only sends, today's counts, the clock read off heartbeats,
 * "allow this address", and the redacted capture export.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Branch} */
function truthOrganisation(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-21 09:00:00', 'UTC'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);

    return [$tenant, Branch::factory()->create(['tenant_id' => $tenant->id])];
}

function truthDevice(Tenant $tenant, Branch $branch, string $adapter, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'connection_config' => $adapter === 'zkteco_adms' ? ['mode' => 'adms'] : [],
        'webhook_token' => $adapter === 'zkteco_adms' ? null : 'truth-token',
        'webhook_ip_allowlist' => ['127.0.0.1'],
        ...$attributes,
    ]);
}

/** @param array<string, mixed> $body */
function truthHikvision(Tenant $tenant, array $body): TestResponse
{
    return test()->withHeader('X-Webhook-Token', 'truth-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", $body);
}

/** @return array<string, mixed> */
function truthEvent(string $badge, string $time): array
{
    return [
        'dateTime' => $time,
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => [
            'majorEventType' => 5, 'subEventType' => 75,
            'employeeNoString' => $badge, 'name' => 'Abebe Kebede', 'attendanceStatus' => 'checkIn',
        ],
    ];
}

/** @return array<string, mixed> */
function truthHeartbeat(?string $time): array
{
    return ['ipAddress' => '10.0.0.7', 'macAddress' => 'aa:bb:cc:dd:ee:ff', 'eventType' => 'heartBeat', 'eventState' => 'active', ...($time === null ? [] : ['dateTime' => $time])];
}

function truthApi(Tenant $tenant, string $method, string $path, array $body = []): TestResponse
{
    return test()->json($method, "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$path}", $body);
}

function allowFixture(array $device = []): array
{
    [$tenant, $branch] = truthOrganisation();
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $model = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'S-ALLOW', 'webhook_ip_allowlist' => ['10.0.0.1'], ...$device]);

    return [$tenant, $model, $admin];
}

const CAPTURE_HOST = 'http://admin.ethr.test/api/v1/admin/devices';

function captureOperator(): User
{
    $user = User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true]);
    test()->actingAs($user);

    return $user;
}
describe('one rule for every terminal that only sends', function () {
    it('is receive-only for ADMS, and for ZKTeco and Suprema with no address, not for one with an address', function () {
        [$tenant, $branch] = truthOrganisation();

        $adms = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'SN-ADMS']);
        $zkNone = truthDevice($tenant, $branch, 'zkteco', ['webhook_token' => 'zk-none']);
        $supremaNone = truthDevice($tenant, $branch, 'suprema', ['webhook_token' => 'sup-none']);
        $zkAddress = truthDevice($tenant, $branch, 'zkteco', ['webhook_token' => 'zk-addr', 'connection_config' => ['ip' => '196.188.1.1', 'port' => 4370]]);
        $generic = truthDevice($tenant, $branch, 'generic', ['webhook_token' => 'generic-1']);

        expect(DeviceManager::receivesOnly($adms))->toBeTrue()
            ->and(DeviceManager::receivesOnly($zkNone))->toBeTrue()
            ->and(DeviceManager::receivesOnly($supremaNone))->toBeTrue()
            ->and(DeviceManager::receivesOnly($zkAddress))->toBeFalse()
            ->and(DeviceManager::receivesOnly($generic))->toBeFalse()
            // An ADMS terminal keeps the roster it last reported.
            ->and(DeviceManager::hasNoRoster($adms))->toBeFalse()
            ->and(DeviceManager::hasNoRoster($zkNone))->toBeTrue();
    });

    it('never pulls an ADMS terminal with auto_sync on, and lets it go quiet and then offline', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();

        $heard = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'S1', 'auto_sync' => true, 'status' => 'online', 'last_sync_at' => now()->subMinutes(5)]);
        $quiet = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'S2', 'auto_sync' => true, 'status' => 'online', 'last_sync_at' => now()->subMinutes(40)]);
        $gone = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'S3', 'auto_sync' => true, 'status' => 'online', 'last_sync_at' => now()->subHours(30)]);
        $zk = truthDevice($tenant, $branch, 'zkteco', ['webhook_token' => 'zk-sync', 'auto_sync' => true, 'status' => 'online', 'last_sync_at' => now()->subHours(30)]);

        app()->forgetScopedInstances();
        test()->artisan('devices:sync')->assertSuccessful();

        $read = fn (Device $d): string => Device::withoutGlobalScope('tenant')->findOrFail($d->id)->status;
        expect($read($heard))->toBe('online')
            ->and($read($quiet))->toBe('quiet')
            ->and($read($gone))->toBe('offline')
            ->and($read($zk))->toBe('offline');
        Queue::assertNotPushed(PullDeviceEventsJob::class);
    });

    it('moves a JSON-webhook terminal heard from on every accepted push', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'zkteco', ['serial_number' => 'ZK1', 'status' => 'offline', 'last_sync_at' => now()->subDays(3)]);

        test()->withHeader('X-Webhook-Token', 'truth-token')
            ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", ['sn' => 'ZK1', 'records' => [['pin' => '1', 'timestamp' => '2026-11-21T08:00:00+03:00']]])
            ->assertOk();

        $fresh = Device::withoutGlobalScope('tenant')->findOrFail($device->id);
        expect($fresh->status)->toBe('online')
            ->and($fresh->last_sync_at->diffInSeconds(now(), true))->toBeLessThan(5);
    });
});

describe('today\'s counts', function () {
    it('moves with a push, and counts a resend as a duplicate', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push'], 'status' => 'pending', 'last_sync_at' => null]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1001', 'status' => EmployeeStatus::CONFIRMED]);

        $event = truthEvent('1001', '2026-11-21T08:02:00+03:00');
        truthHikvision($tenant, $event)->assertOk();
        truthHikvision($tenant, $event)->assertOk();
        truthHikvision($tenant, $event)->assertOk();
        truthHikvision($tenant, truthEvent('7777', '2026-11-21T08:03:00+03:00'))->assertOk();
        truthHikvision($tenant, ['eventType' => 'AccessControllerEvent', 'AccessControllerEvent' => ['employeeNoString' => '1001']])->assertOk();

        $today = fn () => truthApi($tenant, 'GET', $device->public_id)->assertOk()->json('today');

        // Nothing worked yet: two waiting, one with no time kept in quarantine at once.
        expect($today())->toMatchArray(['received' => 5, 'duplicate' => 2, 'waiting' => 2, 'quarantined' => 1, 'recorded' => 0, 'held' => 0]);

        app()->call([new ProcessDeviceInboundPunchesJob($device->id, $tenant->id), 'handle']);
        test()->actingAs($admin);

        expect($today())->toMatchArray(['received' => 5, 'duplicate' => 2, 'waiting' => 0, 'quarantined' => 1, 'recorded' => 1, 'held' => 1, 'day' => '2026-11-21']);
    });

    it('counts a resend on the JSON webhooks and on ADMS as well', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $zk = truthDevice($tenant, $branch, 'zkteco', ['serial_number' => 'ZK-C', 'webhook_token' => 'truth-token']);
        $adms = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'ADMS-C']);

        $body = ['sn' => 'ZK-C', 'records' => [['pin' => '1', 'timestamp' => '2026-11-21T08:00:00+03:00'], ['pin' => '2', 'timestamp' => '2026-11-21T08:01:00+03:00']]];
        foreach ([1, 2] as $_) {
            test()->withHeader('X-Webhook-Token', 'truth-token')->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", $body)->assertOk();
        }

        $line = "7\t2026-11-21 08:00:00\t0\t1\t0\t0\t0\n";
        foreach ([1, 2, 3] as $_) {
            test()->call('POST', '/iclock/cdata?'.http_build_query(['SN' => 'ADMS-C', 'table' => 'ATTLOG']), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $line)->assertOk();
        }

        test()->actingAs($admin);
        truthApi($tenant, 'GET', $zk->public_id)->assertOk()->assertJsonPath('today.duplicate', 2)->assertJsonPath('today.received', 4);
        truthApi($tenant, 'GET', $adms->public_id)->assertOk()->assertJsonPath('today.duplicate', 2)->assertJsonPath('today.received', 3);
    });
    it('counts the device\'s own day, not UTC\'s', function () {
        [$tenant, $branch] = truthOrganisation();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);
        Carbon::setTestNow(Carbon::parse('2026-11-21 22:00:00', 'UTC'));
        truthHikvision($tenant, truthEvent('55', '2026-11-22T01:00:00+03:00'))->assertOk();

        // 22:00 UTC is 01:00 on the 22nd in Addis Ababa.
        truthApi($tenant, 'GET', $device->public_id)->assertOk()->assertJsonPath('today.day', '2026-11-22')->assertJsonPath('today.received', 1);

        Carbon::setTestNow(Carbon::parse('2026-11-22 22:00:00', 'UTC'));
        truthApi($tenant, 'GET', $device->public_id)->assertOk()->assertJsonPath('today.day', '2026-11-23')->assertJsonPath('today.received', 0);
    });

    it('keeps another organisation\'s punches out of the count', function () {
        [$tenant, $branch] = truthOrganisation();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        $other = createTenant();
        $otherDevice = truthDevice($other, Branch::factory()->create(['tenant_id' => $other->id]), 'hikvision', ['connection_config' => ['mode' => 'push'], 'webhook_token' => 'other-token']);
        DeviceInboundPunch::stash([[
            'tenant_id' => $other->id, 'device_id' => $otherDevice->id, 'badge' => '1', 'punch_type' => 'auto',
            'occurred_raw' => '2026-11-21T08:00:00+03:00', 'idempotency_key' => 'other-1',
        ]]);

        app(CurrentTenant::class)->set($tenant);
        test()->actingAs($admin);
        truthApi($tenant, 'GET', $device->public_id)->assertOk()->assertJsonPath('today.received', 0);
    });
});

describe('the clock', function () {
    it('reads a terminal six hours ahead or behind off its heartbeat, whatever the size', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        truthHikvision($tenant, truthHeartbeat('2026-11-21T18:00:00+03:00'))->assertOk()->assertJsonPath('status', 'ignored');
        expect($device->fresh()->clock_offset_seconds)->toBe(21600)
            ->and($device->fresh()->clock_checked_at)->not->toBeNull();

        Carbon::setTestNow(now()->addMinutes(5));
        truthHikvision($tenant, truthHeartbeat('2026-11-21T06:05:00+03:00'))->assertOk();
        expect($device->fresh()->clock_offset_seconds)->toBe(-21600 + 0);
    });

    it('reads a terminal set to the Ethiopian calendar, years out', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        truthHikvision($tenant, truthHeartbeat('2019-03-12T12:00:00+03:00'))->assertOk();

        $expected = Carbon::parse('2019-03-12T12:00:00+03:00')->getTimestamp() - now()->getTimestamp();
        expect($device->fresh()->clock_offset_seconds)->toBe($expected)->and($expected)->toBeLessThan(-86400 * 365 * 7);

        truthHikvision($tenant, truthHeartbeat('2034-03-12T12:00:00+03:00'))->assertOk();
        expect($device->fresh()->clock_offset_seconds)->toBeGreaterThan(86400 * 365 * 7);
    });

    it('says nothing is measurable for a heartbeat with no time, and never stores a zero', function () {
        [$tenant, $branch] = truthOrganisation();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        truthHikvision($tenant, truthHeartbeat(null))->assertOk();

        expect($device->fresh()->clock_offset_seconds)->toBeNull();
        truthApi($tenant, 'GET', $device->public_id)->assertOk()
            ->assertJsonPath('clock_offset_seconds', null)
            ->assertJsonPath('clock_measurable', false)
            ->assertJsonPath('clock_source', 'heartbeat_no_time');
    });

    it('does not take a buffered door event for a heartbeat', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        truthHikvision($tenant, ['dateTime' => '2026-11-20T08:00:00+03:00', 'eventType' => 'doorStatus'])->assertOk();

        expect($device->fresh()->clock_offset_seconds)->toBeNull();
    });

    it('does not read a late punch as a clock that is behind, and keeps a fresh heartbeat reading', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1001', 'status' => EmployeeStatus::CONFIRMED]);
        $run = fn () => app()->call([new ProcessDeviceInboundPunchesJob($device->id, $tenant->id), 'handle']);

        // Kept through an outage and sent two hours late.
        truthHikvision($tenant, truthEvent('1001', '2026-11-21T09:00:00+03:00'))->assertOk();
        $run();
        expect($device->fresh()->clock_offset_seconds)->toBeNull();

        // A heartbeat says six hours ahead; a punch only a little ahead does not overwrite it.
        truthHikvision($tenant, truthHeartbeat('2026-11-21T18:00:00+03:00'))->assertOk();
        Carbon::setTestNow(now()->addMinutes(10));
        truthHikvision($tenant, truthEvent('1001', '2026-11-21T12:12:00+03:00'))->assertOk();
        $run();
        expect($device->fresh()->clock_offset_seconds)->toBe(21600);

        // An hour on, with no heartbeat since, an ahead punch is the evidence there is.
        Carbon::setTestNow(now()->addHours(2));
        truthHikvision($tenant, truthEvent('1001', '2026-11-21T14:15:00+03:00'))->assertOk();
        $run();
        expect($device->fresh()->clock_offset_seconds)->toBeGreaterThan(0)->toBeLessThan(21600);
    });
});

describe('allow this address', function () {

    it('adds the address once, audits it, and clears the refusal it fixes', function () {
        [$tenant, $device] = allowFixture();
        WebhookRejections::record($device, 'ip_not_allowed', '196.188.5.5');

        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])
            ->assertOk()
            ->assertJsonPath('webhook_ip_allowlist', ['10.0.0.1', '196.188.5.5']);

        expect($device->fresh()->webhook_ip_allowlist)->toBe(['10.0.0.1', '196.188.5.5'])
            ->and(WebhookRejections::last($device->fresh()))->toBeNull();

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'device.allowlist_address_added')->sole();
        expect($entry->payload)->toMatchArray(['device' => $device->public_id, 'address' => '196.188.5.5']);
    });

    it('is idempotent and writes no second audit entry', function () {
        [$tenant, $device] = allowFixture();

        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '10.0.0.1'])->assertOk();
        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])->assertOk();
        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])->assertOk();

        expect($device->fresh()->webhook_ip_allowlist)->toBe(['10.0.0.1', '196.188.5.5'])
            ->and(AuditLog::withoutGlobalScopes()->where('action', 'device.allowlist_address_added')->count())->toBe(1);
    });

    it('refuses junk, a range and a full list with 422', function () {
        [$tenant, $device] = allowFixture();

        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => 'not an address'])->assertUnprocessable();
        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.0.0/16'])->assertUnprocessable();
        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", [])->assertUnprocessable();

        $device->update(['webhook_ip_allowlist' => array_map(fn (int $n) => "10.0.0.{$n}", range(1, 20))]);
        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])->assertUnprocessable();
        expect(count($device->fresh()->webhook_ip_allowlist))->toBe(20);
    });

    it('is refused to a user who may only view devices', function () {
        [$tenant, $device] = allowFixture();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        truthApi($tenant, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])->assertForbidden();

        expect($device->fresh()->webhook_ip_allowlist)->toBe(['10.0.0.1']);
    });

    it('is not reachable on another organisation\'s device', function () {
        [$tenant, $device] = allowFixture();
        $other = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $other);

        truthApi($other, 'POST', "{$device->public_id}/allowlist", ['ip' => '196.188.5.5'])->assertNotFound();
    });
});

describe('the capture export', function () {

    it('redacts names, badges, tokens and query strings, and is audited', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);
        truthHikvision($tenant, truthEvent('987654', '2026-11-21T08:02:00+03:00'))->assertOk();
        truthHikvision($tenant, truthEvent('987654', '2026-11-21T08:09:00+03:00'))->assertOk();
        truthHikvision($tenant, truthEvent('555123', '2026-11-21T08:10:00+03:00'))->assertOk();
        truthHikvision($tenant, truthHeartbeat('2026-11-21T12:00:00+03:00') + ['faceURL' => 'http://10.0.0.7/pic.jpg?token=SECRETQUERY', 'personName' => 'Tigist Alemu'])->assertOk();

        captureOperator();
        $response = test()->getJson(CAPTURE_HOST."/{$device->public_id}/capture")->assertOk();
        $text = $response->getContent();

        expect($text)
            ->not->toContain('987654')->not->toContain('555123')
            ->not->toContain('Abebe')->not->toContain('Kebede')->not->toContain('Tigist')
            ->not->toContain('SECRETQUERY')->not->toContain('truth-token')
            ->not->toContain('aa:bb:cc:dd:ee:ff')->not->toContain('10.0.0.7');

        $inbox = $response->json('inbox');
        expect($inbox)->toHaveCount(3)
            ->and($inbox[0]['badge'])->toBe($inbox[1]['badge'])
            ->and($inbox[0]['badge'])->not->toBe($inbox[2]['badge'])
            ->and($inbox[0])->not->toHaveKey('idempotency_key')
            ->and($response->json('heartbeats'))->toHaveCount(1);

        expect(AuditLog::withoutGlobalScopes()->where('action', 'platform.device_capture_exported')->count())->toBe(1);
    });

    it('is refused to an organisation\'s admin, and refused on its host too', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson(CAPTURE_HOST."/{$device->public_id}/capture")->assertForbidden();
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/devices/{$device->public_id}/capture")->assertForbidden();
        expect(AuditLog::withoutGlobalScopes()->where('action', 'platform.device_capture_exported')->count())->toBe(0);
    });

    it('never keeps the token a terminal sends in its address', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision?token=truth-token", truthHeartbeat('2026-11-21T12:00:00+03:00'))->assertOk();

        $kept = HealthSignals::store()->get('device-heartbeats:'.$device->id);
        expect($kept)->toHaveCount(1)
            ->and(json_encode($kept))->not->toContain('truth-token')
            ->and(json_encode($kept))->toContain('heartBeat');
    });

    it('keeps only the last twenty heartbeats', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        foreach (range(1, 25) as $n) {
            Carbon::setTestNow(now()->addMinutes(6));
            truthHikvision($tenant, truthHeartbeat('2026-11-21T12:00:00+03:00') + ['seq' => $n])->assertOk();
        }

        expect(HealthSignals::store()->get('device-heartbeats:'.$device->id))->toHaveCount(20);
    });
});

describe('the redactor', function () {
    it('replaces the PIN of an ADMS line, withholds a line it could not read, and reads through truncated text', function () {
        $redactor = new DeviceCaptureRedactor;

        expect($redactor->raw("1042\t2026-11-21 08:00:00\t0\t1", null))->toBe("BADGE-1\t2026-11-21 08:00:00\t0\t1")
            ->and($redactor->raw("1042\t2026-11-21 09:00:00\t0\t1", null))->toStartWith('BADGE-1')
            ->and($redactor->raw('garbled 1042 Abebe', 'unreadable'))->toBe('[line withheld: could not be read]')
            ->and($redactor->raw('{"name":"Abebe Kebede","employeeNoString":"1042","cardNo":99887766,"faceURL":"http://h/x?token=abc", "more":"...[truncated]', null))
            ->not->toContain('Abebe')->not->toContain('1042')->not->toContain('99887766')->not->toContain('token=abc');
    });

    it('reads a multipart notification whose event_log is a JSON string', function () {
        $redactor = new DeviceCaptureRedactor;
        $body = json_encode(['event_log' => json_encode(['AccessControllerEvent' => ['name' => 'Abebe', 'employeeNoString' => '1042', 'attendanceStatus' => 'checkIn']])]);

        $out = (string) $redactor->raw($body);

        expect($out)->not->toContain('Abebe')->not->toContain('1042')->toContain('checkIn');
    });
});

describe('capture limits and a redaction that keeps the shape', function () {
    it('keeps one heartbeat body per device every five minutes', function () {
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        foreach (range(1, 10) as $_) {
            truthHikvision($tenant, truthHeartbeat('2026-11-21T12:00:00+03:00'))->assertOk();
        }
        expect(HealthSignals::store()->get('device-heartbeats:'.$device->id))->toHaveCount(1);

        Carbon::setTestNow(now()->addMinutes(5)->addSecond());
        truthHikvision($tenant, truthHeartbeat('2026-11-21T12:06:00+03:00'))->assertOk();
        expect(HealthSignals::store()->get('device-heartbeats:'.$device->id))->toHaveCount(2);
    });

    it('keeps the last twenty access events under their own key, body only, and exports them redacted', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();
        $device = truthDevice($tenant, $branch, 'hikvision', ['connection_config' => ['mode' => 'push']]);

        foreach (range(1, 22) as $n) {
            test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision?token=truth-token", truthEvent((string) (5000 + $n), '2026-11-21T08:'.sprintf('%02d', $n).':00+03:00'))->assertOk();
        }

        $kept = HealthSignals::store()->get('device-events:'.$device->id);
        expect($kept)->toHaveCount(20)->and(json_encode($kept))->not->toContain('truth-token');

        captureOperator();
        $text = test()->getJson(CAPTURE_HOST."/{$device->public_id}/capture")->assertOk()->assertJsonCount(20, 'events')->getContent();
        expect($text)->not->toContain('Abebe')->not->toContain('5005')->not->toContain('truth-token');
    });

    it('redacts a realistic access event and leaves what the direction and time depend on', function () {
        $redactor = new DeviceCaptureRedactor;
        $event = [
            'ipAddress' => '10.0.0.7', 'dateTime' => '2026-11-21T08:02:00+03:00', 'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'deviceName' => 'Front', 'majorEventType' => 5, 'subEventType' => 75, 'name' => 'Abebe Kebede',
                'cardNo' => '0099887766', 'cardType' => 1, 'cardReaderNo' => 1, 'doorNo' => 1, 'verifyNo' => 4321,
                'serialNo' => 77, 'currentVerifyMode' => 'cardOrFaceOrFp', 'attendanceStatus' => 'checkOut',
                'label' => 'Check Out', 'employeeNoString' => '1042', 'userType' => 'normal', 'mask' => 'no',
            ],
        ];

        $out = json_decode((string) $redactor->raw(json_encode($event)), true);

        expect(json_encode($out))->not->toContain('Abebe')->not->toContain('1042')->not->toContain('0099887766');
        $kept = $out['AccessControllerEvent'];
        foreach (['cardReaderNo' => 1, 'cardType' => 1, 'verifyNo' => 4321, 'serialNo' => 77, 'currentVerifyMode' => 'cardOrFaceOrFp', 'attendanceStatus' => 'checkOut', 'majorEventType' => 5, 'subEventType' => 75, 'label' => 'Check Out'] as $key => $value) {
            expect($kept[$key])->toBe($value);
        }

        $before = HikvisionEvent::fromNotification($event);
        $after = HikvisionEvent::fromNotification($out);
        expect(HikvisionEvent::direction($after))->toBe(HikvisionEvent::direction($before))->toBe('check_out')
            ->and($after['time'])->toBe($before['time']);
    });
});

describe('a terminal that only sends has nothing to pull', function () {
    it('refuses Pull and Import history with 422, and says the terminal is not pulled', function () {
        Queue::fake();
        [$tenant, $branch] = truthOrganisation();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $adms = truthDevice($tenant, $branch, 'zkteco_adms', ['serial_number' => 'S-NP']);
        $pulledDevice = truthDevice($tenant, $branch, 'generic', ['webhook_token' => 'gen-np']);

        truthApi($tenant, 'GET', $adms->public_id)->assertOk()->assertJsonPath('pulled', false)->assertJsonPath('receives_only', false);
        truthApi($tenant, 'GET', $pulledDevice->public_id)->assertOk()->assertJsonPath('pulled', true);

        truthApi($tenant, 'POST', "{$adms->public_id}/pull")->assertUnprocessable()->assertJsonValidationErrors('device');
        truthApi($tenant, 'POST', "{$adms->public_id}/import-history", ['window' => 'last_30'])->assertUnprocessable();
        Queue::assertNotPushed(PullDeviceEventsJob::class);

        truthApi($tenant, 'POST', "{$pulledDevice->public_id}/pull")->assertOk();
        Queue::assertPushed(PullDeviceEventsJob::class);
        expect($admin->exists)->toBeTrue();
    });
});
