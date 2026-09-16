<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever handles sales that a lead came in.
 *
 * Mail only, and sent on demand to `config('mail.contact_inbox')` rather than to
 * a `User` — the recipient is an inbox, not an account, so there is no
 * notifiable to write a `database` row against. That is why `via()` here is
 * narrower than most notifications in this directory.
 *
 * `ShouldQueue`, unlike its siblings, because this one sits in the request path
 * of an unauthenticated public endpoint: a slow or unreachable SMTP host would
 * otherwise hold the sender's browser open and then fail a submission that was
 * already safely persisted. The row is the record; the mail is the delivery.
 *
 * It carries no message body. The lead's own words stay in the database and out
 * of an inbox that may be a shared alias, forwarded, or retained far longer than
 * the enquiry warrants — the same reasoning that took the payload out of the log
 * line this replaces.
 */
class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Lead $lead) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New ETHR enquiry from {$this->lead->name}")
            ->line("{$this->lead->name} got in touch through the public contact form.")
            ->line('Organization: '.($this->lead->organization ?: 'not given'))
            ->line('Email: '.$this->lead->email)
            ->line('Phone: '.($this->lead->phone ?: 'not given'))
            ->line('Reference: '.$this->lead->public_id);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_public_id' => $this->lead->public_id,
        ];
    }
}
