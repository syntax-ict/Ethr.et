<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the address an organisation was registered with that it now exists,
 * and where it signs in. Registration verified nothing and sent nothing, so
 * the owner of an address used by someone else was never told (production QA
 * 2026-10-11, P7). Sent synchronously, like the activation and reset mails:
 * a queued mail waits for the external cron caller.
 */
class OrganisationRegisteredNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Tenant $tenant) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = (string) $this->tenant->name;
        $url = FrontendUrl::forTenant((string) $this->tenant->subdomain, '/login');

        return (new MailMessage)
            ->subject(__('auth.registered.mail_subject', ['org' => $org]))
            ->greeting(__('auth.registered.mail_greeting'))
            ->line(__('auth.registered.mail_intro', ['org' => $org]))
            ->line(__('auth.registered.mail_address', ['url' => $url]))
            ->action(__('auth.registered.mail_action', ['org' => $org]), $url)
            ->line(__('auth.registered.mail_not_you', ['contact' => $this->contact()]));
    }

    /**
     * Mail comes from a no-reply address, so "reply" would go nowhere: name
     * the contact address the platform admin set, else the contact page.
     */
    private function contact(): string
    {
        $email = PlatformSetting::current()->contact_email;

        return is_string($email) && $email !== ''
            ? $email
            : rtrim((string) config('app.frontend_url'), '/').'/contact';
    }
}
