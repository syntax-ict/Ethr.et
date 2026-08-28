<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalReminderNotification extends Notification
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
            'message' => "You have {$this->pendingCount} pending approval(s) awaiting your review, oldest is {$this->oldestHours} hours old.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notification.approval_reminder_subject'))
            ->line("You have {$this->pendingCount} pending approval(s) that require your attention.")
            ->line("The oldest request has been waiting for {$this->oldestHours} hours.")
            ->action('Review Approvals', url('/approvals'));
    }
}
