<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly AttendanceCorrection $correction,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }
        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'correction_id' => $this->correction->public_id,
            'employee_name' => $this->correction->employee?->name,
            'date' => $this->correction->date->format('Y-m-d'),
            'message' => 'Attendance correction request pending your review',
        ];
    }
}
