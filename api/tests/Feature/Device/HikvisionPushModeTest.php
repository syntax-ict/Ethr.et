<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceManager;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\HikvisionPushAdapter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * A Hikvision terminal on an office network (2026-11-11). On shared hosting
 * ETHR cannot reach a private address and refuses to try, and the form
 * required an address, so such a terminal could not be registered at all:
 * "The device address points to a private or internal network". The push
 * handler never needed the address. A terminal may now be registered with
 * none, and sends its punches to ETHR.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Branch} */
function pushModeOrganisation(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, Branch::factory()->create(['tenant_id' => $tenant->id])];
}

/** @return array<string, mixed> */
function pushModeBody(Branch $branch, array $config): array
{
    return [
        'name' => 'Front door',
        'adapter_type' => 'hikvision',
        'branch_public_id' => $branch->public_id,
        'connection_config' => $config,
    ];
}

it('registers a terminal with no address when it is said to send to ETHR, and gives it a push URL', function () {
    [$tenant, $branch] = pushModeOrganisation();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";

    $created = test()->postJson($url, pushModeBody($branch, ['mode' => 'push']))->assertCreated();

    $device = Device::where('tenant_id', $tenant->id)->firstOrFail();

    expect($created->json('receives_only'))->toBeTrue()
        ->and($created->json('webhook_url'))->toContain('/api/v1/devices/webhook/hikvision?token='.$device->webhook_token)
        ->and(app(DeviceManager::class)->adapter($device))->toBeInstanceOf(HikvisionPushAdapter::class);
});

it('still refuses a terminal whose address was simply left out', function () {
    [$tenant, $branch] = pushModeOrganisation();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";

    test()->postJson($url, pushModeBody($branch, ['port' => 80]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection_config']);

    // The other vendors have no such mode: saying "push" does not excuse them.
    test()->postJson($url, [...pushModeBody($branch, ['mode' => 'push']), 'adapter_type' => 'zkteco'])
        ->assertUnprocessable();

    expect(Device::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('still refuses a private address, and still takes a public one as a terminal ETHR calls', function () {
    config(['devices.allow_private_hosts' => false]);
    [$tenant, $branch] = pushModeOrganisation();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";

    test()->postJson($url, pushModeBody($branch, ['ip' => '192.168.1.64', 'port' => 80]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection_config']);

    $created = test()->postJson($url, pushModeBody($branch, ['ip' => '203.0.113.10', 'port' => 8085]))->assertCreated();

    expect($created->json('receives_only'))->toBeFalse()
        ->and(app(DeviceManager::class)->adapter(Device::where('tenant_id', $tenant->id)->firstOrFail()))
        ->toBeInstanceOf(HikvisionAdapter::class);
});

it('records a punch the terminal sends, as the terminal sends it', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    [$tenant, $branch] = pushModeOrganisation();
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'badge_number' => '7301',
        'hire_date' => '2024-01-01',
    ]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
        'webhook_token' => 'push-mode-token',
        'timezone' => 'Africa/Addis_Ababa',
        'status' => 'pending',
        'last_sync_at' => null,
    ]);
    Tenant::factory()->create();

    // A real request carries no organisation: the token is all there is.
    app()->forgetScopedInstances();
    expect(app(CurrentTenant::class)->get())->toBeNull();

    // Most firmware posts multipart, with the event as JSON in `event_log`.
    test()->post('http://ethr.test/api/v1/devices/webhook/hikvision?token=push-mode-token', [
        'event_log' => json_encode([
            'dateTime' => '2026-11-03T09:00:00+03:00',
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'employeeNoString' => '7301',
                'attendanceStatus' => 'checkIn',
                'majorEventType' => 5,
                'subEventType' => 75,
            ],
        ]),
    ])->assertOk()->assertJsonPath('status', 'processed');

    $record = AttendanceRecord::withoutGlobalScope('tenant')->where('employee_id', $employee->id)->firstOrFail();

    expect($record->tenant_id)->toBe($tenant->id)
        ->and($record->check_in)->not->toBeNull()
        ->and($device->refresh()->status)->toBe('online')
        ->and($device->last_sync_at)->not->toBeNull();
});

it('takes a heartbeat as a sign of life from a terminal that only sends, and from no other', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    [$tenant, $branch] = pushModeOrganisation();
    $make = fn (string $token, array $config): Device => Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => $config,
        'webhook_token' => $token,
        'status' => 'pending',
        'last_sync_at' => null,
    ]);
    $sends = $make('sends-only', ['mode' => 'push']);
    $called = $make('is-called', ['ip' => '203.0.113.10', 'port' => 80]);

    foreach (['sends-only', 'is-called'] as $token) {
        test()->postJson("http://ethr.test/api/v1/devices/webhook/hikvision?token={$token}", [
            'eventType' => 'heartBeat',
            'dateTime' => '2026-11-03T09:00:00+03:00',
        ])->assertOk()->assertJsonPath('status', 'ignored');
    }

    expect($sends->refresh()->last_sync_at)->not->toBeNull()
        ->and($sends->status)->toBe('online')
        // For a terminal ETHR pulls, `last_sync_at` is where the next pull
        // starts: a heartbeat must not move it past punches not yet pulled.
        ->and($called->refresh()->last_sync_at)->toBeNull();
});

it('is never pulled: not by the schedule, and not when asked by hand', function () {
    Http::fake();
    Queue::fake();
    [$tenant, $branch] = pushModeOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
        'auto_sync' => true,
        'status' => 'online',
        'last_sync_at' => null,
    ]);

    test()->artisan('devices:sync')->assertSuccessful();
    Queue::assertNotPushed(PullDeviceEventsJob::class);

    // Run by hand, the job leaves the device as it found it. It used to be
    // able to find nothing, move `last_sync_at` to now, and so make a silent
    // terminal look as if it had just been heard from.
    app()->call([new PullDeviceEventsJob($device->id, $tenant->id), 'handle']);

    expect($device->refresh()->last_sync_at)->toBeNull()
        ->and(DeviceSyncLog::withoutGlobalScope('tenant')->where('device_id', $device->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('says a terminal that only sends is offline once it has been silent for a day, and connects to nothing to find out', function () {
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    [$tenant, $branch] = pushModeOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
        'last_sync_at' => now()->subHours(2),
    ]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/status";

    test()->getJson($url)->assertOk()->assertJsonPath('details.online', true)->assertJsonPath('details.mode', 'push');

    $device->update(['last_sync_at' => now()->subHours(25)]);
    test()->getJson($url)->assertOk()->assertJsonPath('details.online', false);

    Http::assertNothingSent();
});

it('drops the address and the password when a terminal is switched to send to ETHR, and takes them back with an address', function () {
    config(['devices.allow_private_hosts' => false]);
    [$tenant, $branch] = pushModeOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 8085, 'username' => 'admin', 'password' => 'secret'],
    ]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}";

    test()->putJson($url, ['connection_config' => ['mode' => 'push']])
        ->assertOk()
        ->assertJsonPath('receives_only', true);
    expect($device->refresh()->connection_config)->toBe(['mode' => 'push']);

    test()->putJson($url, ['connection_config' => ['ip' => '203.0.113.20', 'port' => 80]])
        ->assertOk()
        ->assertJsonPath('receives_only', false);
    expect($device->refresh()->connection_config)->toBe(['ip' => '203.0.113.20', 'port' => 80]);
});

it('refuses to list the roster of a terminal that only sends to ETHR, instead of answering an empty one', function () {
    Http::fake();
    [$tenant, $branch] = pushModeOrganisation();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
    ]);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1";

    test()->getJson("{$base}/devices/{$device->public_id}/enrollments")
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'device-roster-unavailable')
        ->assertJsonPath('detail', __('device.roster.receives_only'));

    // The refusal comes before the batch: nothing is left behind to read as
    // "nobody is enrolled".
    test()->postJson("{$base}/onboarding/migration/devices/{$device->public_id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'device-roster-unavailable');

    expect(MigrationBatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});
