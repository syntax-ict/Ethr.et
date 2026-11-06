<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Device;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
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
            'location' => $this->device->branch?->name ?? __('notification.unknown_location'),
            'offline_since' => $this->device->last_sync_at?->toIso8601String(),
            ...NotificationMessage::make('notification.inapp.device_offline', ['name' => $this->device->name]),
            'url' => '/devices/'.$this->device->public_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $location = $this->device->branch?->name ?? __('notification.unknown_location');

        return (new MailMessage)
            ->subject(__('notification.device_offline_subject'))
            ->line(__('notification.device_offline_line', ['name' => $this->device->name, 'location' => $location]))
            ->action(__('notification.device_offline_action'), FrontendUrl::forNotifiable($notifiable, '/devices'));
    }
}
