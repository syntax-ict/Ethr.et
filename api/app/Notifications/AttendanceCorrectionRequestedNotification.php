<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\AttendanceCorrection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionRequestedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceCorrection $correction,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::ATTENDANCE_CORRECTION;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        // Corrections have no own `date`; derive it from the record being corrected.
        // loadMissing keeps this safe under preventLazyLoading when the model arrives unhydrated.
        $this->correction->loadMissing('attendanceRecord');
        $date = $this->correction->attendanceRecord?->date;

        return [
            'correction_id' => $this->correction->public_id,
            'employee_name' => $this->correction->employee?->name,
            'date' => $date?->format('Y-m-d'),
            ...NotificationMessage::make('notification.inapp.correction_requested', ['name' => (string) $this->correction->employee?->name]),
            'url' => '/approvals',
        ];
    }
}
