<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\DeviceOffline;
use App\Notifications\DeviceOfflineNotification;
use App\Support\TenantRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeviceOffline implements ShouldQueue
{
    public function handle(DeviceOffline $event): void
    {
        $device = $event->device;

        // A device going offline is a monitoring alert, so the audience is the
        // organisation's active admins, found with `TenantRecipients` (queued: no
        // tenant is resolved). The tenant is derived from the device, which is
        // itself tenant-owned.
        $admins = TenantRecipients::withRoles($device->tenant_id, [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN]);

        foreach ($admins as $admin) {
            $admin->notify(new DeviceOfflineNotification($device));
        }
    }
}
