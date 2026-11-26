<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\DeviceSilent;
use App\Notifications\DeviceSilentNotification;
use App\Support\TenantRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeviceSilent implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(DeviceSilent $event): void
    {
        // The same audience as `NotifyDeviceOffline`: the organisation's
        // active tenant and HR admins, found by the tenant the sweep stated.
        $admins = TenantRecipients::withRoles($event->tenantId, [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN]);

        foreach ($admins as $admin) {
            $admin->notify(new DeviceSilentNotification(
                $event->devicePublicId,
                $event->deviceName,
                $event->branchName,
                $event->kind,
                $event->since,
            ));
        }
    }
}
