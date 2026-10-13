<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A one-time sign-in code by email — the channel every account has, used while
 * no SMS gateway is configured and alongside one once it is.
 *
 * Sent with notifyNow() and never queued or retried: the code lives five
 * minutes, so a delivery that comes later is a code that no longer works.
 */
class SignInCodeNotification extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $minutes,
        private readonly string $organisation,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.otp_email_subject', ['code' => $this->code]))
            ->line(__('auth.otp_email_intro', ['organisation' => $this->organisation]))
            ->line('**'.$this->code.'**')
            ->line(__('auth.otp_email_expiry', ['minutes' => $this->minutes]))
            ->line(__('auth.otp_email_ignore'));
    }
}
