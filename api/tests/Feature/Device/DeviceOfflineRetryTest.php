<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Jobs\PullDeviceEventsJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * A device that failed once used to be parked until an admin pressed Test or
 * Pull (2026-11-15). The scheduler now tries it again, at most every fifteen
 * minutes or its own interval if that is longer, and the admins hear about a
 * failing device once a day, not on every retry.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Branch} */
function retryOrganisation(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, Branch::factory()->create(['tenant_id' => $tenant->id])];
}

function retryDevice(Tenant $tenant, Branch $branch, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 80],
        'status' => 'offline',
        'auto_sync' => true,
        'sync_interval_minutes' => 5,
        'last_sync_at' => now()->subHours(3),
        ...$attributes,
    ]);
}

function retryLog(int $tenantId, Device $device, int $minutesAgo): void
{
    $at = now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');

    DB::table('device_sync_logs')->insert([
        'public_id' => (string) Str::ulid(),
        'tenant_id' => $tenantId,
        'device_id' => $device->id,
        'status' => 'offline',
        'triggered_by' => 'schedule',
        'started_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** The scheduler runs with no request and no organisation resolved. */
function retryRun(): void
{
    app()->forgetScopedInstances();
    test()->artisan('devices:sync')->assertSuccessful();
}

it('pulls an offline or error device again once its last attempt is old enough, and not before', function (string $status) {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $old = retryDevice($tenant, $branch, ['status' => $status]);
    $recent = retryDevice($tenant, $branch, ['status' => $status]);
    retryLog($tenant->id, $old, 16);
    retryLog($tenant->id, $recent, 5);

    retryRun();

    Queue::assertPushed(PullDeviceEventsJob::class, 1);
    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $old->id && $job->tenantId === $tenant->id);
})->with(['offline', 'error']);

it('uses the latest attempt, not the oldest', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch);
    retryLog($tenant->id, $device, 600);
    retryLog($tenant->id, $device, 3);

    retryRun();

    Queue::assertNotPushed(PullDeviceEventsJob::class);
});

it('pulls an offline device that has no attempt on record', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch);

    retryRun();

    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $device->id);
});

it('waits for the device interval when that is longer than fifteen minutes', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch, ['sync_interval_minutes' => 60]);

    retryLog($tenant->id, $device, 20);
    retryRun();
    Queue::assertNotPushed(PullDeviceEventsJob::class);

    DB::table('device_sync_logs')->where('device_id', $device->id)->delete();
    retryLog($tenant->id, $device, 61);
    retryRun();
    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $device->id);
});

it('does not retry a device with auto sync off', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    retryDevice($tenant, $branch, ['auto_sync' => false]);

    retryRun();

    Queue::assertNotPushed(PullDeviceEventsJob::class);
});

it('never retries an ADMS terminal, which comes back by its own next call', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch, ['adapter_type' => 'zkteco_adms', 'connection_config' => []]);
    retryLog($tenant->id, $device, 600);

    retryRun();

    Queue::assertNotPushed(PullDeviceEventsJob::class);
});

it('does not count a log row of another organisation as this device attempt', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $other = createTenant();
    $device = retryDevice($tenant, $branch);
    // A row for the same device id under another organisation, one minute old.
    retryLog($other->id, $device, 1);

    retryRun();

    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $device->id);
});

it('still dispatches an online device on its interval, as before', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $due = retryDevice($tenant, $branch, ['status' => 'online', 'last_sync_at' => now()->subMinutes(10)]);
    retryDevice($tenant, $branch, ['status' => 'online', 'last_sync_at' => now()->subMinutes(1)]);

    retryRun();

    Queue::assertPushed(PullDeviceEventsJob::class, 1);
    Queue::assertPushed(PullDeviceEventsJob::class, fn (PullDeviceEventsJob $job) => $job->deviceId === $due->id);
});

it('tells the admins once a day while a device keeps failing, whatever happens in between', function () {
    Event::fake([DeviceSyncFailed::class]);
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch, ['status' => 'error']);
    $job = fn () => new PullDeviceEventsJob($device->id, $tenant->id);

    $job()->failed(new RuntimeException('first'));
    $job()->failed(new RuntimeException('second'));
    Event::assertDispatchedTimes(DeviceSyncFailed::class, 1);

    // The terminal answers and the pull completes. That does not re-enable the notice.
    Http::fake(['*' => Http::response(['AcsEvent' => ['responseStatusStrg' => 'OK']], 200)]);
    app()->call([$job(), 'handle']);
    expect($device->fresh()->status)->toBe('online');
    $job()->failed(new RuntimeException('third'));
    Event::assertDispatchedTimes(DeviceSyncFailed::class, 1);

    Carbon::setTestNow(now()->addHours(25));
    $job()->failed(new RuntimeException('fourth'));
    Event::assertDispatchedTimes(DeviceSyncFailed::class, 2);
});

it('tells the admins a device went offline once a day, however often it flaps', function () {
    Event::fake([DeviceOffline::class]);
    [$tenant, $branch] = retryOrganisation();
    $device = retryDevice($tenant, $branch, ['status' => 'online']);
    $run = fn () => app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);
    // A fake already registered wins over a later one, so the link is a flag.
    $linkUp = false;
    Http::fake(['*' => function () use (&$linkUp) {
        if (! $linkUp) {
            throw new ConnectionException('cURL error 7: Connection refused');
        }

        return Http::response(['AcsEvent' => ['responseStatusStrg' => 'OK']], 200);
    }]);

    $run();
    expect($device->fresh()->status)->toBe('offline');
    Event::assertDispatchedTimes(DeviceOffline::class, 1);

    $linkUp = true;
    $run();
    expect($device->fresh()->status)->toBe('online');

    $linkUp = false;
    $run();
    expect($device->fresh()->status)->toBe('offline');
    Event::assertDispatchedTimes(DeviceOffline::class, 1);

    Carbon::setTestNow(now()->addHours(25));
    $linkUp = true;
    $run();
    $linkUp = false;
    $run();
    Event::assertDispatchedTimes(DeviceOffline::class, 2);
});

it('caps the notices per device, not across devices', function () {
    Event::fake([DeviceSyncFailed::class]);
    [$tenant, $branch] = retryOrganisation();
    $one = retryDevice($tenant, $branch, ['status' => 'error']);
    $two = retryDevice($tenant, $branch, ['status' => 'error']);

    (new PullDeviceEventsJob($one->id, $tenant->id))->failed(new RuntimeException('down'));
    (new PullDeviceEventsJob($two->id, $tenant->id))->failed(new RuntimeException('down'));
    (new PullDeviceEventsJob($two->id, $tenant->id))->failed(new RuntimeException('down'));

    Event::assertDispatchedTimes(DeviceSyncFailed::class, 2);
});

it('Sync all pulls offline and error devices too, and leaves out a terminal that only sends', function () {
    Queue::fake();
    [$tenant, $branch] = retryOrganisation();
    $offline = retryDevice($tenant, $branch, ['status' => 'offline']);
    $error = retryDevice($tenant, $branch, ['status' => 'error']);
    $online = retryDevice($tenant, $branch, ['status' => 'online']);
    $push = retryDevice($tenant, $branch, ['status' => 'online', 'connection_config' => ['mode' => 'push']]);
    // Another organisation's device is none of this request's business.
    $other = createTenant();
    retryDevice($other, Branch::factory()->create(['tenant_id' => $other->id]), ['status' => 'offline']);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/sync-all");

    $response->assertOk()->assertJson(['dispatched' => 3]);
    $queued = [];
    Queue::assertPushed(PullDeviceEventsJob::class, 3);
    Queue::assertPushed(PullDeviceEventsJob::class, function (PullDeviceEventsJob $job) use (&$queued, $tenant) {
        $queued[] = $job->deviceId;

        return $job->tenantId === $tenant->id;
    });
    expect($queued)->toEqualCanonicalizing([$offline->id, $error->id, $online->id])
        ->and($queued)->not->toContain($push->id);
});
