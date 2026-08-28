<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingPunchNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly Employee $employee,
        private readonly string $date,
        private readonly string $type, // 'missing_check_out' | 'missing_check_in'
    ) {}

    protected function preferenceType(): string
    {
        return 'attendance_anomaly';
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
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
        $label = $this->type === 'missing_check_out' ? 'check-out' : 'check-in';

        return (new MailMessage)
            ->subject(__('notification.missing_punch_subject'))
            ->line("{$this->employee->name} is missing a {$label} for {$this->date}.")
            ->line('Review their attendance record and request a correction if needed.')
            ->action('View Attendance', url('/attendance'));
    }
}
