<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceOfflineNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Device $device,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'device_id' => $this->device->public_id,
            'device_name' => $this->device->name,
            'location' => $this->device->branch?->name ?? 'Unknown',
            'offline_since' => $this->device->last_sync_at?->toIso8601String(),
            'message' => "Device \"{$this->device->name}\" has gone offline.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = $this->device->branch?->name ?? 'Unknown location';

        return (new MailMessage)
            ->subject(__('notification.device_offline_subject'))
            ->line("Biometric device \"{$this->device->name}\" at {$location} has gone offline.")
            ->action('View Device Status', url('/devices'));
    }
}
