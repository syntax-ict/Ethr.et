<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingPunchNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Employee $employee,
        private readonly string $date,
        private readonly string $type, // 'missing_check_out' | 'missing_check_in'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employee_id' => $this->employee->public_id,
            'employee_name' => $this->employee->name,
            'date' => $this->date,
            'type' => $this->type,
            'message' => $this->type === 'missing_check_out'
                ? "{$this->employee->name} did not check out on {$this->date}"
                : "{$this->employee->name} did not check in on {$this->date}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = ['name' => $this->employee->name, 'date' => $this->date];

        $subject = $this->type === 'missing_check_out'
            ? __('notification.missing_check_out_subject', $params)
            : __('notification.missing_check_in_subject', $params);

        $body = $this->type === 'missing_check_out'
            ? __('notification.missing_check_out_body', $params)
            : __('notification.missing_check_in_body', $params);

        return (new MailMessage())
            ->subject($subject)
            ->line($body)
            ->line("Employee: {$this->employee->name}")
            ->line("Date: {$this->date}");
    }
}
