<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notice listing the contracts, probation periods and documents due for
 * this person today (SendExpiryRemindersJob).
 */
class ExpiryReminderNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    /** The most lines a single e-mail lists; the rest are counted. */
    private const MAIL_LINES = 25;

    /**
     * @param  list<array{kind: string, name: string, date: string, label: string}>  $lines
     */
    public function __construct(private readonly array $lines) {}

    protected function preferenceType(): string
    {
        return 'expiry_reminder';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'count' => count($this->lines),
            'items' => $this->lines,
            'message' => trans_choice('reminders.expiry.summary', count($this->lines), ['count' => count($this->lines)]),
            'url' => '/employees',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(trans_choice('reminders.expiry.subject', count($this->lines), ['count' => count($this->lines)]))
            ->line(__('reminders.expiry.intro'));

        foreach (array_slice($this->lines, 0, self::MAIL_LINES) as $line) {
            $mail->line(__("reminders.expiry.line_{$line['kind']}", [
                'name' => $line['name'],
                'date' => $line['date'],
                'document' => $line['label'],
            ]));
        }

        $more = count($this->lines) - self::MAIL_LINES;
        if ($more > 0) {
            $mail->line(__('reminders.expiry.more', ['count' => $more]));
        }

        return $mail->action(__('reminders.expiry.action'), FrontendUrl::forNotifiable($notifiable, '/employees'));
    }
}
