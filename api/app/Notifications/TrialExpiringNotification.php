<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $daysRemaining,
        private readonly string $trialEndsAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'days_remaining' => $this->daysRemaining,
            'trial_ends_at' => $this->trialEndsAt,
            'message' => "Your trial expires in {$this->daysRemaining} day(s). Upgrade to continue using ETHR.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('notification.trial_expiring_subject'))
            ->line("Your ETHR trial will expire in {$this->daysRemaining} day(s) on {$this->trialEndsAt}.")
            ->line('Upgrade now to ensure uninterrupted access for your team.')
            ->action('Upgrade Plan', url('/billing'));
    }
}
