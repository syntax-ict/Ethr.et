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

        $admins = User::where('tenant_id', $device->tenant_id)
            ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DeviceOfflineNotification($device));
        }
    }
}
