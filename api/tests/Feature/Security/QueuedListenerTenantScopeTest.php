<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Events\DeviceSyncFailed;
use App\Listeners\NotifyDeviceOffline;
use App\Listeners\NotifyDeviceSyncFailed;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DeviceOfflineNotification;
use App\Notifications\DeviceSyncFailedNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Stating the tenant predicate is not enough on its own.
 *
 * `NotifyDeviceOffline` is a queued listener, and it did say which tenant it
 * meant — `User::where('tenant_id', $device->tenant_id)`. But it never dropped
 * the global scope, so `BelongsToTenant` added its own clause on top. On a
 * worker, with no tenant resolved, that clause is `whereRaw('0 = 1')`, and the
 * query becomes:
 *
 *     where 0 = 1 and tenant_id = <the right tenant>
 *
 * which matches nobody, always. A device going offline is a monitoring alert;
 * silently notifying no one is the worst available outcome.
 *
 * This is the same shape BASELINE §15d already recorded in
 * `HandleOverdueInvoicesJob` — "the job carries no HTTP tenant context, so
 * `BelongsToTenant` resolved to `whereRaw('0 = 1')` and it found nobody, every
 * time". Fixed there, still live here.
 *
 * Before `CurrentTenant` became a `scoped` binding this could appear to work,
 * but only when the previous job on that worker happened to leave *this*
 * tenant resolved. Failing closed makes it deterministic, which is how it
 * became findable.
 */
it('notifies the tenant admins of a device going offline, from a worker', function () {
    Notification::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    // The listener is queued, so it runs with whatever the worker has — which,
    // now that the binding is scoped, is nothing.
    app(CurrentTenant::class)->forget();

    (new NotifyDeviceOffline)->handle(new DeviceOffline($device));

    Notification::assertSentTo($admin, DeviceOfflineNotification::class);
});

it('does not notify an admin belonging to another tenant', function () {
    Notification::fake();

    $owner = Tenant::factory()->create(['subdomain' => 'owner']);
    $other = Tenant::factory()->create(['subdomain' => 'other']);

    app(CurrentTenant::class)->set($owner);
    $ownerAdmin = User::factory()->create([
        'tenant_id' => $owner->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);
    $ownerBranch = Branch::factory()->create(['tenant_id' => $owner->id]);
    $device = Device::factory()->create([
        'tenant_id' => $owner->id,
        'branch_id' => $ownerBranch->id,
    ]);

    app(CurrentTenant::class)->set($other);
    $otherAdmin = User::factory()->create([
        'tenant_id' => $other->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);

    app(CurrentTenant::class)->forget();

    (new NotifyDeviceOffline)->handle(new DeviceOffline($device));

    // Dropping the global scope must not widen the query: the explicit
    // predicate is what keeps the other tenant's admin out of it.
    Notification::assertSentTo($ownerAdmin, DeviceOfflineNotification::class);
    Notification::assertNotSentTo($otherAdmin, DeviceOfflineNotification::class);
});

/**
 * The same guarantee for the error state, which had no notification at all.
 *
 * `DeviceOffline` covers "the adapter says the device is unreachable".
 * `DeviceSyncFailed` covers "our sync threw and exhausted its retries" — a
 * different state, which the `Device` model already distinguishes and which
 * nobody was ever told about. `PullDeviceEventsJob` had no `failed()`.
 *
 * Its listener carries the identical bypass for the identical reason, so it
 * carries the identical risk of the identical regression: state the predicate,
 * forget the bypass, notify nobody, silently.
 */
it('notifies the tenant admins of a device sync failure, from a worker', function () {
    Notification::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'acme-sync']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    app(CurrentTenant::class)->forget();

    (new NotifyDeviceSyncFailed)->handle(new DeviceSyncFailed($device, 'adapter timed out'));

    Notification::assertSentTo($admin, DeviceSyncFailedNotification::class);
});

it('does not notify another tenant of a device sync failure', function () {
    Notification::fake();

    $owner = Tenant::factory()->create(['subdomain' => 'owner-sync']);
    $other = Tenant::factory()->create(['subdomain' => 'other-sync']);

    app(CurrentTenant::class)->set($owner);
    // `devices.branch_id` is NOT NULL and DeviceFactory does not supply one —
    // every device fixture in this file creates a branch first.
    $ownerBranch = Branch::factory()->create(['tenant_id' => $owner->id]);
    $device = Device::factory()->create([
        'tenant_id' => $owner->id,
        'branch_id' => $ownerBranch->id,
    ]);

    $outsider = User::factory()->create([
        'tenant_id' => $other->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);

    app(CurrentTenant::class)->forget();

    (new NotifyDeviceSyncFailed)->handle(new DeviceSyncFailed($device, 'adapter timed out'));

    Notification::assertNotSentTo($outsider, DeviceSyncFailedNotification::class);
});

it('carries the reason to the admin, because which kind of failure it is matters', function () {
    Notification::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'reason-sync']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::HR_ADMIN->value,
    ]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'Gate Reader 3',
    ]);

    app(CurrentTenant::class)->forget();

    (new NotifyDeviceSyncFailed)->handle(new DeviceSyncFailed($device, 'TLS handshake failed'));

    // Unlike the scheduled-report and digest failure notices, this one DOES
    // carry the reason: the audience is tenant_admin/hr_admin users of the
    // device's own tenant, who are already entitled to its configuration.
    // Without it the alert says only "something broke", and the whole point of
    // separating `error` from `offline` is telling an admin which they have.
    Notification::assertSentTo($admin, DeviceSyncFailedNotification::class, function ($notification) use ($admin) {
        $mail = $notification->toMail($admin);
        $rendered = $mail->subject.' '.implode(' ', $mail->introLines);

        expect($rendered)->toContain('TLS handshake failed');
        expect($rendered)->toContain('Gate Reader 3');

        // And the subject must not be a raw translation key. DeviceOffline's
        // was, for its whole life: `notification.device_offline_subject`
        // existed in neither lang/en nor lang/am, so every offline alert went
        // out with the key as its subject. An en/am parity gate cannot catch a
        // key missing from both.
        expect($mail->subject)->not->toStartWith('notification.');

        return true;
    });
});

it('gives the offline alert a real subject line too', function () {
    // The pre-existing defect above, pinned directly rather than only as a
    // side-assertion on its sibling.
    $tenant = Tenant::factory()->create(['subdomain' => 'subject-check']);
    app(CurrentTenant::class)->set($tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN->value,
    ]);

    $subject = (new DeviceOfflineNotification($device))->toMail($admin)->subject;

    expect($subject)->not->toStartWith('notification.');
    expect($subject)->not->toBe('notification.device_offline_subject');
});
