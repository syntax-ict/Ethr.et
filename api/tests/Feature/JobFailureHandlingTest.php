<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Jobs\PullDeviceEventsJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Models\Device;
use App\Notifications\SystemAlertNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('GenerateMonthlyInvoicesJob notifies every super admin when it fails entirely', function () {
    Notification::fake();

    $tenant = createTenant();
    $superAdmin1 = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    $superAdmin2 = createUser(['role' => UserRole::SUPER_ADMIN], createTenant());
    createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    (new GenerateMonthlyInvoicesJob)->failed(new RuntimeException('DB connection lost'));

    Notification::assertSentTo($superAdmin1, SystemAlertNotification::class);
    Notification::assertSentTo($superAdmin2, SystemAlertNotification::class);
    Notification::assertCount(2);
});

test('HandleOverdueInvoicesJob notifies every super admin when it fails entirely', function () {
    Notification::fake();

    $tenant = createTenant();
    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);

    (new HandleOverdueInvoicesJob)->failed(new RuntimeException('DB connection lost'));

    Notification::assertSentTo($superAdmin, SystemAlertNotification::class);
});

test('CleanupExpiredDataJob failure is handled without throwing and without alerting anyone', function () {
    Notification::fake();

    (new CleanupExpiredDataJob)->failed(new RuntimeException('disk full'));

    Notification::assertNothingSent();
});

test('ScanMissingPunchesJob failure is handled without throwing and without alerting anyone', function () {
    Notification::fake();

    (new ScanMissingPunchesJob(1, now()->format('Y-m-d')))->failed(new RuntimeException('timeout'));

    Notification::assertNothingSent();
});

/**
 * `PullDeviceEventsJob` had no `failed()` at all.
 *
 * docs/CLAUDE.md's queue-recovery table claimed this path "triggers
 * DeviceOffline, notifies admin". `DeviceOffline` is dispatched only from the
 * SUCCESS path — when the adapter reports the device unreachable — so on an
 * exception no event fired and no admin was told. An unreachable device
 * politely notified an admin; a device whose sync threw twice notified nobody.
 */
test('PullDeviceEventsJob failure alerts the tenant with DeviceSyncFailed, not DeviceOffline', function () {
    Event::fake([DeviceSyncFailed::class, DeviceOffline::class]);

    $tenant = createTenant();
    $device = Device::factory()->create(['tenant_id' => $tenant->id]);

    (new PullDeviceEventsJob($device))->failed(new RuntimeException('TLS handshake failed'));

    Event::assertDispatched(
        DeviceSyncFailed::class,
        fn (DeviceSyncFailed $event) => $event->device->is($device)
            && $event->reason === 'TLS handshake failed',
    );

    // Deliberately a DIFFERENT event. `offline` (the adapter's reachability
    // check says down) and `error` (our sync machinery threw) are distinct
    // states the Device model already tracks, and an admin needs to know which
    // one they have: a device that is off is someone else's problem to power
    // on, and a device that is erroring is ours. Reusing DeviceOffline here
    // would have thrown that distinction away.
    Event::assertNotDispatched(DeviceOffline::class);
});
