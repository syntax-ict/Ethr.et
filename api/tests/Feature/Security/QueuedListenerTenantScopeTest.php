<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Listeners\NotifyDeviceOffline;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DeviceOfflineNotification;
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
