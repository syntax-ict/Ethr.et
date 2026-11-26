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
 * Accounts that follow their job title are waiting for someone who may give
 * them the role it names. Built from a plain count so it reads the same on a
 * worker with no organisation resolved.
 */
class TitleAccessHeldNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly int $count) {}

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
            'count' => $this->count,
            ...NotificationMessage::make('notification.inapp.title_access_held', ['count' => $this->count], count: $this->count),
            'url' => '/settings/users?title=held',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(trans_choice('notification.title_access_held_subject', $this->count, ['count' => $this->count]))
            ->line(trans_choice('notification.title_access_held_line', $this->count, ['count' => $this->count]))
            ->line(__('notification.title_access_held_hint'))
            ->action(__('notification.title_access_held_action'), FrontendUrl::forNotifiable($notifiable, '/settings/users?title=held'));
    }
}
