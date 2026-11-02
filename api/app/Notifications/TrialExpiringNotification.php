<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use App\Support\NotificationTemplates;
use App\Support\ReaderDates;
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
            ...NotificationMessage::make('notification.inapp.trial_expiring', ['days' => $this->daysRemaining], count: $this->daysRemaining),
            'url' => '/billing',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Sent to the tenant's own admins, so the recipient's tenant is the one
        // whose template applies. A notifiable without one gets the built-in text.
        $tenantId = $notifiable->tenant_id ?? null;

        return NotificationTemplates::mail('trial_expiring', is_int($tenantId) || is_string($tenantId) ? $tenantId : null, [
            'days_remaining' => $this->daysRemaining,
            'trial_ends_at' => ReaderDates::for($notifiable)->date($this->trialEndsAt),
        ])->action(__('notification.upgrade_plan'), FrontendUrl::forNotifiable($notifiable, '/billing'));
    }
}
