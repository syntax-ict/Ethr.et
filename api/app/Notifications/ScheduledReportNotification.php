<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the report's actual data, not just a "your report ran" alert.
 * `RunScheduledReportsJob` previously notified recipients via the generic
 * `SystemAlertNotification` with a row count and nothing else — a scheduled
 * statutory filing report never reached anyone with data they could actually
 * submit. This attaches the CSV export inline.
 */
class ScheduledReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $name,
        private readonly int $rowCount,
        private readonly string $csvContent,
        private readonly string $filename,
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
            'title' => __('report.scheduled_subject', ['name' => $this->name]),
            'message' => __('report.scheduled_body', ['name' => $this->name, 'rows' => $this->rowCount]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('report.scheduled_subject', ['name' => $this->name]))
            ->line(__('report.scheduled_body', ['name' => $this->name, 'rows' => $this->rowCount]));

        if ($this->rowCount > 0) {
            $message->attachData($this->csvContent, $this->filename, ['mime' => 'text/csv']);
        }

        return $message;
    }
}
