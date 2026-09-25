<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\DeviceSyncFailed;
use App\Models\User;
use App\Notifications\DeviceSyncFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeviceSyncFailed implements ShouldQueue
{
    public function handle(DeviceSyncFailed $event): void
    {
        $device = $event->device;

        // Same bypass and same justification as `NotifyDeviceOffline`, which
        // sits next to this file: the listener is queued, so no tenant is
        // resolved and `BelongsToTenant` would add `whereRaw('0 = 1')` on top
        // of the predicate below — the query would read
        // `where 0 = 1 and tenant_id = <the right tenant>` and match nobody.
        //
        // The tenant predicate is re-applied here explicitly, derived from
        // `$device->tenant_id`, and the device is itself tenant-owned. That is
        // the rule root CLAUDE.md states for every one of the pinned bypass
        // sites, and this one raises the pin from 157 to 158.
        //
        // The failure mode if this were got wrong is the same one
        // `NotifyDeviceOffline` already suffered and was fixed for: notifying
        // nobody, silently, which is indistinguishable from nothing having gone
        // wrong. That is exactly the defect this listener exists to end.
        $admins = User::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DeviceSyncFailedNotification($device, $event->reason));
        }
    }
}
