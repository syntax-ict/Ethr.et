<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Events\DeviceSilent;
use App\Listeners\NotifyDeviceSilent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DeviceSilentNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * A terminal that only sends to ETHR has no pull job to notice when it goes
 * quiet. `devices:watch-silence` reads it from what it sent: an ADMS terminal
 * from its pings, any other from its punches. The organisation is at work
 * 08:30 to 17:30 Addis Ababa time, Monday to Friday, in these tests (the
 * factory's default shift). 2026-11-24 is a Tuesday; 08:30 there is 05:30 UTC.
 */

afterEach(fn () => Carbon::setTestNow());

/** Freeze the clock at a local Addis Ababa wall time. */
function silenceAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Africa/Addis_Ababa'));
}

/**
 * A local Addis Ababa time as the UTC instant a model stores: Eloquent writes
 * a Carbon in its own zone without converting it.
 */
function silenceUtc(string $local): Carbon
{
    return Carbon::parse($local, 'Africa/Addis_Ababa')->utc();
}

/** @return array{0: Tenant, 1: Branch, 2: User, 3: User} */
function silenceOrganisation(array $settings = []): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa', 'settings' => $settings]);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'badge_number' => '1001',
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    return [$tenant, $branch, $admin, $hr];
}

function silenceAdms(Tenant $tenant, Branch $branch, ?string $lastHeardLocal, array $over = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'connection_config' => ['mode' => 'adms'],
        'last_sync_at' => $lastHeardLocal === null ? null : silenceUtc($lastHeardLocal),
        'created_at' => silenceUtc('2026-11-01 09:00'),
        ...$over,
    ]);
}

function silencePush(Tenant $tenant, Branch $branch, array $over = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'hikvision',
        'connection_config' => [],
        'last_sync_at' => silenceUtc('2026-11-23 16:00'),
        'created_at' => silenceUtc('2026-11-01 09:00'),
        ...$over,
    ]);
}

function silencePunch(Tenant $tenant, Device $device, string $localTime): void
{
    $at = silenceUtc($localTime);

    DB::table('device_inbound_punches')->insert([
        'public_id' => (string) Str::ulid(),
        'tenant_id' => $tenant->id,
        'device_id' => $device->id,
        'badge' => '1001',
        'punch_type' => 'check_in',
        'occurred_raw' => '2026-11-24 08:00:00',
        'idempotency_key' => 'k-'.$device->id.'-'.$localTime,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/** The scheduler runs with no request and no organisation resolved. */
function runSilenceWatch(): void
{
    app()->forgetScopedInstances();
    test()->artisan('devices:watch-silence')->assertSuccessful();
}

it('tells the admins about an ADMS terminal not heard for 30 minutes, not 29', function (string $now, bool $told) {
    Notification::fake();
    silenceAt($now);
    [$tenant, $branch, $admin, $hr] = silenceOrganisation();
    silenceAdms($tenant, $branch, '2026-11-24 10:00');

    runSilenceWatch();

    if ($told) {
        Notification::assertSentTo($admin, DeviceSilentNotification::class);
        Notification::assertSentTo($hr, DeviceSilentNotification::class);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    '29 minutes' => ['2026-11-24 10:29:00', false],
    '30 minutes' => ['2026-11-24 10:30:00', true],
    '31 minutes' => ['2026-11-24 10:31:00', true],
]);

it('tells the admins about a push terminal with no punch an hour after the day began, not 59 minutes', function (string $now, bool $told) {
    Notification::fake();
    silenceAt($now);
    [$tenant, $branch, $admin, $hr] = silenceOrganisation();
    silencePush($tenant, $branch);

    runSilenceWatch();

    if ($told) {
        Notification::assertSentTo($admin, DeviceSilentNotification::class);
        Notification::assertSentTo($hr, DeviceSilentNotification::class);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    '59 minutes after 08:30' => ['2026-11-24 09:29:00', false],
    '60 minutes after 08:30' => ['2026-11-24 09:30:00', true],
    '61 minutes after 08:30' => ['2026-11-24 09:31:00', true],
]);

it('does not blame a push terminal that has punched today, even before the shift began', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    $device = silencePush($tenant, $branch);
    silencePunch($tenant, $device, '2026-11-24 07:45');

    runSilenceWatch();

    Notification::assertNothingSent();
});

it('does not take a Hikvision heartbeat for a punch', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch, $admin] = silenceOrganisation();
    silencePush($tenant, $branch, ['last_sync_at' => silenceUtc('2026-11-24 10:58'), 'status' => 'online']);

    runSilenceWatch();

    Notification::assertSentTo($admin, DeviceSilentNotification::class);
});

it('measures an ADMS terminal from the start of the working day, not from last night', function (string $now, bool $told) {
    Notification::fake();
    silenceAt($now);
    [$tenant, $branch, $admin] = silenceOrganisation();
    silenceAdms($tenant, $branch, '2026-11-23 17:00');

    runSilenceWatch();

    $told ? Notification::assertSentToTimes($admin, DeviceSilentNotification::class, 1) : Notification::assertNothingSent();
})->with([
    'one minute into the day' => ['2026-11-24 08:31:00', false],
    '29 minutes into the day' => ['2026-11-24 08:59:00', false],
    '30 minutes into the day' => ['2026-11-24 09:00:00', true],
]);

it('stays quiet outside working hours', function (string $now) {
    Notification::fake();
    silenceAt($now);
    [$tenant, $branch] = silenceOrganisation();
    silenceAdms($tenant, $branch, '2026-11-20 10:00');
    silencePush($tenant, $branch);

    runSilenceWatch();

    Notification::assertNothingSent();
})->with([
    'before the shift' => ['2026-11-24 07:00:00'],
    'after the shift' => ['2026-11-24 18:00:00'],
    'a Saturday' => ['2026-11-28 11:00:00'],
    'a Sunday' => ['2026-11-29 11:00:00'],
]);

it('stays quiet on a holiday, and a branch holiday silences that branch only', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch, $admin] = silenceOrganisation();
    $other = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $other->id, 'badge_number' => '2001', 'status' => EmployeeStatus::CONFIRMED]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $other->id, 'date' => '2026-11-24', 'recurring' => false]);
    silencePush($tenant, $other, ['name' => 'Closed branch']);

    runSilenceWatch();
    Notification::assertNothingSent();

    silencePush($tenant, $branch, ['name' => 'Open branch']);
    runSilenceWatch();

    Notification::assertSentToTimes($admin, DeviceSilentNotification::class, 1);
    Notification::assertSentTo($admin, DeviceSilentNotification::class, fn ($n) => str_contains((string) json_encode($n->toArray($admin)), 'Open branch'));

    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-11-24', 'recurring' => false]);
    Notification::fake();
    app('cache')->flush();
    runSilenceWatch();
    Notification::assertNothingSent();
});

it('stays quiet while the setting is off, and honours the minutes it is given', function () {
    Notification::fake();
    silenceAt('2026-11-24 10:40');
    [$tenant, $branch, $admin] = silenceOrganisation(['device_silence_alerts' => false]);
    silenceAdms($tenant, $branch, '2026-11-24 10:00');

    runSilenceWatch();
    Notification::assertNothingSent();

    $tenant->update(['settings' => ['device_silence_alerts' => true, 'device_silence_adms_minutes' => 60]]);
    runSilenceWatch();
    Notification::assertNothingSent();

    $tenant->update(['settings' => ['device_silence_adms_minutes' => 40]]);
    runSilenceWatch();
    Notification::assertSentTo($admin, DeviceSilentNotification::class);
});

it('tells the admins once a day per device, and again the next working day', function () {
    Event::fake([DeviceSilent::class]);
    silenceAt('2026-11-24 10:40');
    [$tenant, $branch] = silenceOrganisation();
    silenceAdms($tenant, $branch, '2026-11-24 10:00');

    runSilenceWatch();
    silenceAt('2026-11-24 10:55');
    runSilenceWatch();
    silenceAt('2026-11-24 14:00');
    runSilenceWatch();
    Event::assertDispatchedTimes(DeviceSilent::class, 1);

    silenceAt('2026-11-25 10:40');
    runSilenceWatch();
    Event::assertDispatchedTimes(DeviceSilent::class, 2);
});

it('leaves a pulled terminal to its own notice', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'connection_config' => ['ip' => '203.0.113.10', 'port' => 80],
        'last_sync_at' => silenceUtc('2026-11-23 10:00'),
        'created_at' => silenceUtc('2026-11-01 09:00'),
    ]);

    runSilenceWatch();

    Notification::assertNothingSent();
});

it('says nothing of a branch where nobody holds a badge, or where they have all left', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant] = silenceOrganisation();
    $empty = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $gone = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $gone->id, 'badge_number' => '3001', 'status' => EmployeeStatus::TERMINATED]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $empty->id, 'badge_number' => null, 'status' => EmployeeStatus::CONFIRMED]);
    silencePush($tenant, $empty);
    silencePush($tenant, $gone);

    runSilenceWatch();

    Notification::assertNothingSent();
});

it('leaves out a terminal never heard from, and one registered after the day began', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    silenceAdms($tenant, $branch, null);
    silencePush($tenant, $branch, ['created_at' => silenceUtc('2026-11-24 09:00')]);
    silencePush($tenant, $branch, ['last_sync_at' => null, 'status' => 'pending']);

    runSilenceWatch();

    Notification::assertNothingSent();
});

it('says nothing for an organisation with no default shift', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    Shift::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
    silencePush($tenant, $branch);

    runSilenceWatch();

    Notification::assertNothingSent();
});

it('measures a shift that crosses midnight from the day it started', function () {
    Notification::fake();
    [$tenant, $branch, $admin] = silenceOrganisation();
    Shift::query()->where('tenant_id', $tenant->id)->update(['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]);
    silenceAdms($tenant, $branch, '2026-11-24 23:00');

    silenceAt('2026-11-25 01:00');
    runSilenceWatch();

    Notification::assertSentTo($admin, DeviceSilentNotification::class);
});

it('never lets one organisation hear about another\'s device, whichever is visited first', function (bool $silentFirst) {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$a, $branchA, $adminA, $hrA] = silenceOrganisation();
    [$b, $branchB, $adminB, $hrB] = silenceOrganisation();

    silenceAdms($silentFirst ? $a : $b, $silentFirst ? $branchA : $branchB, '2026-11-24 10:00', ['name' => 'Silent one']);
    silenceAdms($silentFirst ? $b : $a, $silentFirst ? $branchB : $branchA, '2026-11-24 10:59', ['name' => 'Healthy one']);

    runSilenceWatch();

    [$heard, $deaf] = $silentFirst ? [[$adminA, $hrA], [$adminB, $hrB]] : [[$adminB, $hrB], [$adminA, $hrA]];
    foreach ($heard as $user) {
        Notification::assertSentTo($user, DeviceSilentNotification::class, fn ($n) => str_contains((string) json_encode($n->toArray($user)), 'Silent one'));
    }
    foreach ($deaf as $user) {
        Notification::assertNotSentTo($user, DeviceSilentNotification::class);
    }
})->with([true, false]);

it('writes the notice in the reader\'s language, without a long dash, an exclamation mark or an emoji', function (string $locale) {
    silenceAt('2026-11-24 11:00');
    [, $branch, $admin] = silenceOrganisation();
    app()->setLocale($locale);

    foreach (['adms', 'push'] as $kind) {
        $notification = new DeviceSilentNotification('01HX', 'Gate 1', $branch->name, $kind, '2026-11-24T07:00:00+00:00');
        $mail = $notification->toMail($admin);
        $text = implode(' ', [$mail->subject, ...$mail->introLines, $mail->actionText, $notification->toArray($admin)['message']]);

        expect($text)->not->toContain('device_silent')->not->toContain('notification.')
            ->and($text)->not->toMatch('/[—–!]|[\x{1F300}-\x{1FAFF}]/u')
            ->and($text)->toContain('Gate 1');
    }
})->with(['en', 'am', 'om']);

it('saves the silence settings, answers them, and refuses a value out of range', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/settings";

    test()->getJson($url)->assertOk()
        ->assertJsonPath('devices.silence_alerts', true)
        ->assertJsonPath('devices.silence_adms_minutes', 30)
        ->assertJsonPath('devices.silence_push_grace_minutes', 60);

    test()->putJson($url, ['settings' => ['device_silence_alerts' => false, 'device_silence_adms_minutes' => 45, 'device_silence_push_grace_minutes' => 90]])->assertOk();

    test()->getJson($url)->assertOk()
        ->assertJsonPath('devices.silence_alerts', false)
        ->assertJsonPath('devices.silence_adms_minutes', 45)
        ->assertJsonPath('devices.silence_push_grace_minutes', 90);

    foreach ([['device_silence_adms_minutes' => 5], ['device_silence_adms_minutes' => 999], ['device_silence_push_grace_minutes' => 10], ['device_silence_alerts' => 'maybe']] as $bad) {
        test()->putJson($url, ['settings' => $bad])->assertStatus(422);
    }
});

it('lists the silent terminals on the health dashboard, the same ones the notice names', function () {
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $adms = silenceAdms($tenant, $branch, '2026-11-24 10:00', ['name' => 'Silent ADMS']);
    silenceAdms($tenant, $branch, '2026-11-24 10:59', ['name' => 'Healthy ADMS']);
    $push = silencePush($tenant, $branch, ['name' => 'Silent push']);
    test()->actingAs($hr);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/dashboard")->assertOk();

    expect(collect($response->json('silent'))->sortBy('name')->values()->all())->toBe([
        ['device_public_id' => $adms->public_id, 'name' => 'Silent ADMS', 'kind' => 'adms', 'since' => silenceUtc('2026-11-24 10:00')->toIso8601String()],
        ['device_public_id' => $push->public_id, 'name' => 'Silent push', 'kind' => 'push', 'since' => silenceUtc('2026-11-24 08:30')->toIso8601String()],
    ]);

    $tenant->update(['settings' => ['device_silence_alerts' => false]]);
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/dashboard")->assertOk()->assertJsonPath('silent', []);
});

it('finds its audience by the tenant it carries when no organisation is resolved, and never an outsider or a suspended user', function () {
    Notification::fake();
    $a = createTenant();
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $a);
    $suspended = createUser(['role' => UserRole::HR_ADMIN, 'status' => 'suspended'], $a);
    $b = createTenant();
    $outsider = createUser(['role' => UserRole::TENANT_ADMIN], $b);
    app()->forgetScopedInstances();

    (new NotifyDeviceSilent)->handle(new DeviceSilent($a->id, '01HXDEVICE', 'Gate 1', null, 'adms', '2026-11-24T07:00:00+00:00'));

    Notification::assertSentTo($admin, DeviceSilentNotification::class);
    Notification::assertNotSentTo($suspended, DeviceSilentNotification::class);
    Notification::assertNotSentTo($outsider, DeviceSilentNotification::class);
});

it('counts a punch of the last night shift, which arrives after midnight, as no evidence for tonight', function () {
    Notification::fake();
    [$tenant, $branch, $admin] = silenceOrganisation();
    Shift::query()->where('tenant_id', $tenant->id)->update(['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]);
    $device = silencePush($tenant, $branch);
    silencePunch($tenant, $device, '2026-11-25 05:50');

    silenceAt('2026-11-25 23:30');
    runSilenceWatch();

    Notification::assertSentTo($admin, DeviceSilentNotification::class);

    Notification::fake();
    app('cache')->flush();
    silencePunch($tenant, $device, '2026-11-25 22:20');
    runSilenceWatch();
    Notification::assertNothingSent();
});

it('counts one night shift as one day for the once-a-day guard, and the next night as another', function () {
    Event::fake([DeviceSilent::class]);
    [$tenant, $branch] = silenceOrganisation();
    Shift::query()->where('tenant_id', $tenant->id)->update(['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]);
    $device = silenceAdms($tenant, $branch, '2026-11-24 22:10');

    silenceAt('2026-11-24 23:00');
    runSilenceWatch();
    silenceAt('2026-11-25 01:00');
    runSilenceWatch();
    Event::assertDispatchedTimes(DeviceSilent::class, 1);

    $device->forceFill(['last_sync_at' => silenceUtc('2026-11-25 22:10')])->save();
    silenceAt('2026-11-25 23:00');
    runSilenceWatch();
    Event::assertDispatchedTimes(DeviceSilent::class, 2);
});

it('gives the notice back when it could not be sent, so the next sweep tries again', function () {
    silenceAt('2026-11-24 11:00');
    [$tenant, $branch] = silenceOrganisation();
    $device = silenceAdms($tenant, $branch, '2026-11-24 10:00');
    Event::listen(DeviceSilent::class, fn () => throw new RuntimeException('queue is down'));

    runSilenceWatch();

    expect(app('cache')->has("device-notice:silent:{$device->id}:2026-11-24"))->toBeFalse();
});

it('does not count a suspended employee as someone who could punch', function () {
    Notification::fake();
    silenceAt('2026-11-24 11:00');
    [$tenant] = silenceOrganisation();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '4001', 'status' => EmployeeStatus::SUSPENDED]);
    silencePush($tenant, $branch);

    runSilenceWatch();

    Notification::assertNothingSent();
});
