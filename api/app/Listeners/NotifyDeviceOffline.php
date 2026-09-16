<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Models\User;
use App\Notifications\DeviceOfflineNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeviceOffline implements ShouldQueue
{
    public function handle(DeviceOffline $event): void
    {
        $device = $event->device;

        // The predicate below was always right; the missing piece was dropping
        // the global scope. This listener is queued, so no tenant is resolved
        // and `BelongsToTenant` added `whereRaw('0 = 1')` on top of it — the
        // query read `where 0 = 1 and tenant_id = <the right tenant>` and
        // matched nobody, every time. A device going offline is a monitoring
        // alert, so the failure mode was notifying no one, silently.
        //
        // Same shape as the admin lookup fixed in `HandleOverdueInvoicesJob`
        // (BASELINE §15d). The tenant is derived from the device, which is
        // itself tenant-owned.
        $admins = User::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DeviceOfflineNotification($device));
        }
    }
}
