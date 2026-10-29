<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceIntegrityFlag;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A punch scored high enough to need a person's decision. In the app (and,
 * through the push listener, on subscribed phones); not by mail, which would
 * turn a busy morning into an inbox of alerts.
 */
class AttendanceHighRiskNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceIntegrityFlag $flag,
        private readonly string $employeeName,
        private readonly int $score,
    ) {}

    protected function preferenceType(): string
    {
        return 'attendance_anomaly';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database']);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'flag_id' => $this->flag->public_id,
            'employee_name' => $this->employeeName,
            'score' => $this->score,
            ...NotificationMessage::make('notification.inapp.attendance_high_risk', ['name' => $this->employeeName, 'score' => $this->score]),
            'url' => '/attendance/integrity',
        ];
    }
}
