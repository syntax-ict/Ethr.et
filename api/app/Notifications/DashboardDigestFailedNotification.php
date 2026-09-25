<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The other half of `DashboardDigestNotification`, and the sibling of
 * `ScheduledReportFailedNotification`.
 *
 * Recipients were told when a digest arrived and nothing when it did not, so a
 * digest that silently stopped looked identical to one nobody had opened. An
 * executive digest is read for the numbers in it; its absence is not obviously
 * an error to the person expecting it.
 *
 * The failure reason is deliberately NOT in the message, for the same reason
 * `ScheduledReportFailedNotification` omits it: `recipients` is a free-text
 * list on the digest rather than a set of authenticated users, and an exception
 * message can carry column names, SQL fragments or a connection string. The
 * reason is stored on the digest's `last_error`.
 */
class DashboardDigestFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $frequency,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('dashboard.digest_failed_subject', ['frequency' => $this->frequency]),
            'message' => __('dashboard.digest_failed_body'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('dashboard.digest_failed_subject', ['frequency' => $this->frequency]))
            ->line(__('dashboard.digest_failed_body'));
    }
}
