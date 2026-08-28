<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a login account is provisioned for someone (invited team member,
 * or an employee created via the form/CSV import). It carries a password-broker
 * token so the recipient can set their own password and activate the account.
 *
 * The link points at the same /login/reset page used by the forgot-password
 * flow — completing it flips the account from `invited` to `active`.
 */
class AccountActivationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $tenantSubdomain,
        private readonly string $organizationName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->email ?? '';
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        $url = "{$frontendUrl}/login/reset?token={$this->token}&email=".urlencode($email)."&tenant={$this->tenantSubdomain}";

        return (new MailMessage)
            ->subject(__('user.activation.subject', ['org' => $this->organizationName]))
            ->greeting(__('user.activation.greeting'))
            ->line(__('user.activation.intro', ['org' => $this->organizationName]))
            ->action(__('user.activation.action'), $url)
            ->line(__('user.activation.expiry'))
            ->line(__('user.activation.ignore'));
    }
}
