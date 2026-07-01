<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingPunchNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $date,
        private readonly string $punchType = 'check_out',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $label = $this->punchType === 'check_in' ? 'check-in' : 'check-out';

        return [
            'date' => $this->date,
            'punch_type' => $this->punchType,
            'message' => "Missing {$label} detected for {$this->date}. Please submit a correction request.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->punchType === 'check_in' ? 'check-in' : 'check-out';

        return (new MailMessage())
            ->subject(__('notification.missing_punch_subject'))
            ->line("A missing {$label} was detected for {$this->date}.")
            ->line('Please submit a correction request if this is incorrect.')
            ->action('Submit Correction', url('/attendance/corrections'));
    }
}
