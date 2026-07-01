<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DeviceOfflineNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Device $device,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'device_public_id' => $this->device->public_id,
            'device_name' => $this->device->name,
            'branch_name' => $this->device->branch?->name,
            'adapter_type' => $this->device->adapter_type,
            'last_sync_at' => $this->device->last_sync_at?->toIso8601String(),
            'message' => "Device '{$this->device->name}' is offline",
            'type' => 'device_offline',
        ];
    }
}
