<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Find my organisation": the sign-in link for each organisation an address
 * belongs to, sent to that address and only to it.
 *
 * Queued, so the request that triggers it takes the same time whether there is
 * anything to send or not — a response that came back slower when the address
 * belonged somewhere would leak what the identical message hides.
 */
class OrganisationSignInLinksNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{subdomain: string, name: string}>  $organisations
     */
    public function __construct(private readonly array $organisations) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('auth.find_organisation.mail_subject'))
            ->line(__('auth.find_organisation.mail_intro'));

        foreach ($this->organisations as $organisation) {
            $mail->line('['.$organisation['name'].']('.FrontendUrl::forTenant($organisation['subdomain'], '/login').')');
        }

        // One organisation is the common case; give it a button as well.
        if (count($this->organisations) === 1) {
            $mail->action(
                __('auth.find_organisation.mail_action'),
                FrontendUrl::forTenant($this->organisations[0]['subdomain'], '/login'),
            );
        }

        return $mail->line(__('auth.find_organisation.mail_ignore'));
    }
}
