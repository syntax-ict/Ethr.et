<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\DeviceSyncFailed;
use App\Notifications\DeviceSyncFailedNotification;
use App\Support\TenantRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeviceSyncFailed implements ShouldQueue
{
    public function handle(DeviceSyncFailed $event): void
    {
        $device = $event->device;

        // Same audience and lookup as `NotifyDeviceOffline`: the organisation's active
        // admins, tenant derived from the device, which is itself tenant-owned.
        $admins = TenantRecipients::withRoles($device->tenant_id, [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN]);

        foreach ($admins as $admin) {
            $admin->notify(new DeviceSyncFailedNotification($device, $event->reason));
        }
    }
}
