<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Notifications\Contracts\BulkMail;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalReminderNotification extends Notification implements BulkMail
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly int $pendingCount,
        private readonly int $oldestHours,
    ) {}

    protected function preferenceType(): string
    {
        return 'approval_reminder';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'pending_count' => $this->pendingCount,
            'oldest_hours' => $this->oldestHours,
            ...NotificationMessage::make('notification.inapp.approval_reminder', ['count' => $this->pendingCount, 'hours' => $this->oldestHours]),
            'url' => '/approvals',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notification.approval_reminder_subject'))
            ->line(__('notification.approval_reminder_line', ['count' => $this->pendingCount]))
            ->line(__('notification.approval_reminder_oldest', ['hours' => $this->oldestHours]))
            ->action(__('notification.approval_reminder_action'), FrontendUrl::forNotifiable($notifiable, '/approvals'));
    }
}
