<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceOfflineNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $deviceName,
        private readonly string $devicePublicId,
        private readonly string $location,
        private readonly string $offlineSince,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'device_id' => $this->devicePublicId,
            'device_name' => $this->deviceName,
            'location' => $this->location,
            'offline_since' => $this->offlineSince,
            'message' => "Device \"{$this->deviceName}\" at {$this->location} has gone offline.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('notification.device_offline_subject'))
            ->line("Biometric device \"{$this->deviceName}\" at {$this->location} has gone offline.")
            ->line("Offline since: {$this->offlineSince}")
            ->action('View Device Status', url('/devices'));
    }
}
