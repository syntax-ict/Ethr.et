<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use App\Support\NotificationTemplates;
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
        // Sent to the tenant's own admins, so the recipient's tenant is the one
        // whose template applies. A notifiable without one gets the built-in text.
        $tenantId = $notifiable->tenant_id ?? null;

        return NotificationTemplates::mail('trial_expiring', is_int($tenantId) || is_string($tenantId) ? $tenantId : null, [
            'days_remaining' => $this->daysRemaining,
            'trial_ends_at' => $this->trialEndsAt,
        ])->action('Upgrade Plan', FrontendUrl::to('/billing'));
    }
}
