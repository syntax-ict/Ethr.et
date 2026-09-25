<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The other half of `ScheduledReportNotification`.
 *
 * Recipients were told when a scheduled report succeeded and told nothing when
 * it failed, so a report that silently stopped arriving looked the same as a
 * report nobody had looked at. For a statutory filing on a deadline those are
 * very different situations, and the recipient is the only person positioned to
 * notice.
 *
 * The failure reason is deliberately NOT in the message. The exception text can
 * carry column names, SQL fragments or a connection string, and these
 * recipients are free-text email addresses on the schedule rather than
 * authenticated users of the tenant — `RunScheduledReportsJob` sends to whatever
 * `recipients` holds. The reason is stored on the schedule's `last_error`,
 * readable through the API by someone who is authenticated and tenant-scoped.
 */
class ScheduledReportFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $name,
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
            'title' => __('report.scheduled_failed_subject', ['name' => $this->name]),
            'message' => __('report.scheduled_failed_body', ['name' => $this->name]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('report.scheduled_failed_subject', ['name' => $this->name]))
            ->line(__('report.scheduled_failed_body', ['name' => $this->name]));
    }
}
