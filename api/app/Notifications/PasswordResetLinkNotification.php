<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $tenantSubdomain,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->email ?? '';
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        $resetUrl = "{$frontendUrl}/login/reset?token={$this->token}&email=".urlencode($email)."&tenant={$this->tenantSubdomain}";

        return (new MailMessage())
            ->subject(__('Reset your ETHR password'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password'), $resetUrl)
            ->line(__('This link will expire in 60 minutes.'))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }
}
