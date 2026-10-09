<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their second factor was turned off by an administrator. The
 * reset is the way back from a lost phone, and also the way around MFA for
 * whoever holds the permission; the email is how the owner finds out if they
 * did not ask for it.
 */
class MfaResetNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('user.mfa_reset.subject'))
            ->line(__('user.mfa_reset.intro'))
            ->line(__('user.mfa_reset.next'))
            ->line(__('user.mfa_reset.not_you'));
    }
}
