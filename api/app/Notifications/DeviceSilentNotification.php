<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use App\Support\LiveUpdates;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A terminal that only sends has gone quiet. Built from plain values so it
 * reads the same on a worker with no organisation resolved.
 */
class DeviceSilentNotification extends Notification
{
    use Queueable;

    /** @param  'adms'|'push'  $kind */
    public function __construct(
        private readonly string $devicePublicId,
        private readonly string $deviceName,
        private readonly ?string $branchName,
        private readonly string $kind,
        private readonly string $since,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];
        if (LiveUpdates::enabled()) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'device_id' => $this->devicePublicId,
            'device_name' => $this->deviceName,
            'location' => $this->location(),
            'kind' => $this->kind,
            'silent_since' => $this->since,
            ...NotificationMessage::make(
                $this->kind === 'adms' ? 'notification.inapp.device_silent_adms' : 'notification.inapp.device_silent_push',
                ['name' => $this->deviceName],
            ),
            'url' => '/devices/'.$this->devicePublicId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = ['name' => $this->deviceName, 'location' => $this->location()];
        $adms = $this->kind === 'adms';

        return (new MailMessage)
            ->subject($adms ? __('notification.device_silent_adms_subject', $replace) : __('notification.device_silent_push_subject', $replace))
            ->line($adms ? __('notification.device_silent_adms_line', $replace) : __('notification.device_silent_push_line', $replace))
            ->line(__('notification.device_silent_hint'))
            ->action(__('notification.device_silent_action'), FrontendUrl::forNotifiable($notifiable, '/devices/'.$this->devicePublicId));
    }

    private function location(): string
    {
        return $this->branchName ?? __('notification.unknown_location');
    }
}
