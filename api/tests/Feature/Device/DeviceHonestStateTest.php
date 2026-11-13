<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Jobs\PullDeviceEventsJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Models\Tenant;
use App\Services\Device\HikvisionPushAdapter;
use App\Support\DeviceErrorCode;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * A Hikvision terminal that only sends reports one of three honest states
 * (2026-11-12). Online means ETHR heard from it, so a terminal nobody has
 * contacted is "not connected yet", and one that has gone quiet stops reading
 * Online. The pull job never touches such a device, so the scheduler is what
 * moves it. The failed pull also says why, as a code the dictionaries turn
 * into advice.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Branch} */
function honestOrganisation(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, Branch::factory()->create(['tenant_id' => $tenant->id])];
}

function honestPushDevice(Tenant $tenant, Branch $branch, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
        ...$attributes,
    ]);
}

it('reads the same three states from last_sync_at, with the day as a strict edge', function () {
    [$tenant, $branch] = honestOrganisation();

    $at = fn (?Carbon $when): string => HikvisionPushAdapter::statusFor(
        honestPushDevice($tenant, $branch, ['last_sync_at' => $when]),
    );

    expect($at(null))->toBe('pending')
        ->and($at(now()->subMinutes(5)))->toBe('online')
        ->and($at(now()->subHours(24)->addSecond()))->toBe('online')
        ->and($at(now()->subHours(24)))->toBe('offline')
        ->and($at(now()->subDays(3)))->toBe('offline');
});

it('moves a terminal that only sends to the rule on every run, whatever it was and whatever auto_sync says', function () {
    Queue::fake();
    [$tenant, $branch] = honestOrganisation();

    // The Habru row: a failed pull wrote offline, then it was switched to push.
    $never = honestPushDevice($tenant, $branch, ['status' => 'offline', 'last_sync_at' => null, 'auto_sync' => false]);
    // Online forever after its first punch, though it has been quiet for three days.
    $quiet = honestPushDevice($tenant, $branch, ['status' => 'online', 'last_sync_at' => now()->subDays(3)]);
    $heard = honestPushDevice($tenant, $branch, ['status' => 'pending', 'last_sync_at' => now()->subHour()]);
    // Another organisation's terminal, which the same run must settle on its own terms.
    $other = createTenant();
    // Neither Hikvision nor auto-synced: nothing for this run, so it must not be loaded, which also keeps its encrypted config from being decrypted every run.
    $manual = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'zkteco', 'auto_sync' => false, 'status' => 'online']);
    $otherDevice = honestPushDevice($other, Branch::factory()->create(['tenant_id' => $other->id]), ['status' => 'pending', 'last_sync_at' => now()->subMinutes(5)]);

    // The scheduler runs with no request and no organisation resolved.
    app()->forgetScopedInstances();
    // The ids of the devices the run hydrates: handleDevice() would leave $manual alone anyway, so only this shows it was never fetched.
    $loaded = [];
    Device::retrieved(function (Device $device) use (&$loaded) {
        $loaded[] = $device->id;
    });
    test()->artisan('devices:sync')->assertSuccessful();

    $read = fn (Device $d): string => Device::withoutGlobalScope('tenant')->findOrFail($d->id)->status;
    expect($loaded)->toContain($never->id, $quiet->id, $heard->id, $otherDevice->id)
        ->and($loaded)->not->toContain($manual->id)
        ->and($read($never))->toBe('pending')
        ->and($read($quiet))->toBe('offline')
        ->and($read($heard))->toBe('online')
        ->and($read($otherDevice))->toBe('online')
        ->and($read($manual))->toBe('online');
    Queue::assertNotPushed(PullDeviceEventsJob::class);
});

it('reports a device that cannot be read and carries on with the rest of the run', function () {
    Queue::fake();
    [$tenant, $branch] = honestOrganisation();
    $broken = honestPushDevice($tenant, $branch, ['status' => 'online', 'last_sync_at' => now()->subDays(3)]);
    // A config that no longer decrypts (a rotated key, a hand edit).
    Device::withoutGlobalScope('tenant')->whereKey($broken->id)->toBase()->update(['connection_config' => 'not-ciphertext']);
    $later = createTenant();
    $laterDevice = honestPushDevice($later, Branch::factory()->create(['tenant_id' => $later->id]), ['status' => 'online', 'last_sync_at' => now()->subDays(3)]);

    app()->forgetScopedInstances();
    test()->artisan('devices:sync')->assertSuccessful();

    expect(Device::withoutGlobalScope('tenant')->findOrFail($laterDevice->id)->status)->toBe('offline');
});

it('leaves a device alone when it is switched to send to ETHR while a pull is running', function () {
    [$tenant, $branch] = honestOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 80],
        'status' => 'pending',
        'last_sync_at' => null,
    ]);
    // The terminal answers, and in that moment an administrator switches it.
    Http::fake(function () use ($device) {
        Device::withoutGlobalScope('tenant')->findOrFail($device->id)->update(['connection_config' => ['mode' => 'push']]);

        return Http::response(['AcsEvent' => ['responseStatusStrg' => 'OK']], 200);
    });

    app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);

    $after = Device::withoutGlobalScope('tenant')->findOrFail($device->id);
    expect($after->last_sync_at)->toBeNull()
        ->and($after->status)->toBe('pending');

    $log = DeviceSyncLog::withoutGlobalScope('tenant')->where('device_id', $device->id)->sole();
    expect($log->status)->toBe('success');
});

function honestPulledDevice(Tenant $tenant, Branch $branch): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 80],
        'status' => 'online',
        'last_sync_at' => null,
    ]);
}

it('records no failed sync when the terminal is switched to send to ETHR while a pull cannot reach it', function () {
    Event::fake([DeviceOffline::class]);
    [$tenant, $branch] = honestOrganisation();
    $device = honestPulledDevice($tenant, $branch);
    Http::fake(function () use ($device) {
        Device::withoutGlobalScope('tenant')->findOrFail($device->id)->update(['connection_config' => ['mode' => 'push']]);

        return Http::response('', 503);
    });

    app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);

    expect(DeviceSyncLog::withoutGlobalScope('tenant')->where('device_id', $device->id)->count())->toBe(0)
        ->and(Device::withoutGlobalScope('tenant')->findOrFail($device->id)->status)->toBe('online');
    Event::assertNotDispatched(DeviceOffline::class);
});

it('drops a pull that fails after the terminal is switched to send to ETHR, without a failure or a retry', function () {
    [$tenant, $branch] = honestOrganisation();
    $device = honestPulledDevice($tenant, $branch);
    $calls = 0;
    Http::fake(function () use ($device, &$calls) {
        // The status probe answers; the event search is where it breaks.
        if (++$calls === 1) {
            return Http::response(['AcsEvent' => ['responseStatusStrg' => 'OK']], 200);
        }
        Device::withoutGlobalScope('tenant')->findOrFail($device->id)->update(['connection_config' => ['mode' => 'push']]);

        throw new ConnectionException('cURL error 7');
    });

    app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);

    expect(DeviceSyncLog::withoutGlobalScope('tenant')->where('device_id', $device->id)->count())->toBe(0)
        ->and(Device::withoutGlobalScope('tenant')->findOrFail($device->id)->status)->toBe('online');
});

it('does not rewrite a row whose status is already right', function () {
    [$tenant, $branch] = honestOrganisation();
    $device = honestPushDevice($tenant, $branch, ['status' => 'pending', 'last_sync_at' => null]);
    $stamp = Carbon::parse('2026-11-12 08:00:00', 'UTC');
    Device::withoutGlobalScope('tenant')->whereKey($device->id)->toBase()->update(['updated_at' => $stamp]);

    test()->artisan('devices:sync')->assertSuccessful();

    expect($device->refresh()->updated_at->equalTo($stamp))->toBeTrue();
});

it('still dispatches a pull for a terminal ETHR calls, and only for one that is due', function () {
    Queue::fake();
    Http::fake();
    [$tenant, $branch] = honestOrganisation();
    $called = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 80],
        'auto_sync' => true,
        'status' => 'online',
        'last_sync_at' => now()->subHour(),
    ]);
    Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.11', 'port' => 80],
        'auto_sync' => false,
        'status' => 'online',
        'last_sync_at' => null,
    ]);

    test()->artisan('devices:sync')->assertSuccessful();

    Queue::assertPushed(PullDeviceEventsJob::class, 1);
    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $called->id);
});

it('clears an Offline left by a failed pull when the terminal is switched to send, and says pending', function () {
    [$tenant, $branch] = honestOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 8000, 'username' => 'admin', 'password' => 'secret'],
        'status' => 'offline',
        // The pull cursor of an earlier life must not read as "heard a moment ago".
        'last_sync_at' => now()->subMinutes(10),
    ]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}";

    test()->putJson($url, ['connection_config' => ['mode' => 'push']])
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('receives_only', true)
        ->assertJsonPath('last_sync_at', null);

    // Back to an address: nothing has tried it yet.
    $device->update(['status' => 'online', 'last_sync_at' => now()]);
    test()->putJson($url, ['connection_config' => ['ip' => '203.0.113.20', 'port' => 80]])
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('receives_only', false);
});

it('answers Test for a terminal that only sends from when it was last heard, never offline for one not yet contacted', function () {
    Http::fake();
    [$tenant, $branch] = honestOrganisation();
    $device = honestPushDevice($tenant, $branch, ['status' => 'offline', 'last_sync_at' => null]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/status";

    test()->getJson($url)->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('error_code', null);
    expect($device->refresh()->status)->toBe('pending');

    $device->update(['last_sync_at' => now()->subHour()]);
    test()->getJson($url)->assertOk()->assertJsonPath('status', 'online');

    Http::assertNothingSent();
});

it('keeps the cause of a failed pull, and publishes a code for it', function () {
    [$tenant, $branch] = honestOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 8000],
        'status' => 'online',
    ]);
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to 203.0.113.10 port 8000 after 12 ms: Connection refused')]);

    app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);

    $log = DeviceSyncLog::withoutGlobalScope('tenant')->where('device_id', $device->id)->firstOrFail();
    expect($device->refresh()->status)->toBe('offline')
        ->and($log->error_message)->toContain('Connection refused')->toContain('port 8000');

    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/sync-logs";
    test()->getJson($url)->assertOk()->assertJsonPath('data.0.error_code', 'sdk_port');
});

it('stores no credentials or query string from a transport error', function () {
    $stored = DeviceErrorCode::scrub('cURL error 28: timed out for https://user:pass@mw.local/events?key=abc123');

    expect($stored)->toBe('cURL error 28: timed out for https://mw.local/events')
        ->and(DeviceErrorCode::scrub(str_repeat('x', 900)))->toHaveLength(500);
});

it('says SDK port only for a Hikvision terminal', function () {
    $refused = 'cURL error 7: Failed to connect to 196.190.67.12 port 8000: Connection refused';

    expect(DeviceErrorCode::from($refused, 'hikvision'))->toBe('sdk_port')
        ->and(DeviceErrorCode::from($refused, 'suprema'))->toBe('refused')
        ->and(DeviceErrorCode::from($refused, 'zkteco'))->toBe('refused')
        ->and(DeviceErrorCode::from($refused))->toBe('refused');
});

it('derives one code per cause', function (?string $message, ?string $code) {
    expect(DeviceErrorCode::from($message, 'hikvision'))->toBe($code);
})->with([
    'nothing' => [null, null],
    'blank' => ['  ', null],
    'refused on the HTTP port' => ['cURL error 7: Failed to connect to 1.2.3.4 port 80 after 5 ms: Connection refused', 'refused'],
    'refused on the SDK port' => ['cURL error 7: Failed to connect to 196.190.67.12 port 8000: Connection refused', 'sdk_port'],
    'timed out on the SDK port' => ['cURL error 28: Connection timed out after 10001 milliseconds for http://1.2.3.4:8000/x port 8000', 'sdk_port'],
    'timed out' => ['cURL error 28: Operation timed out after 10001 milliseconds', 'timeout'],
    'name did not resolve' => ['cURL error 6: Could not resolve host: door.example.test', 'dns'],
    'another cURL error' => ['cURL error 35: SSL connect error', 'other'],
    'sign-in refused' => ['HTTP 401', 'auth'],
    'forbidden' => ['HTTP 403', 'auth'],
    'another status' => ['HTTP 404', 'http'],
    'the old message' => ['Device is not reachable', 'unreachable'],
    'something else' => ['Stopped at 2026-11-01T10:00:00+03:00: the device returned a full page', 'other'],
]);
