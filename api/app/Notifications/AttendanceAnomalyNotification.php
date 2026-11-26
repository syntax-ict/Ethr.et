<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AttendanceAnomalyNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly string $employeeName,
        private readonly string $anomalyType,
        private readonly string $date,
        private readonly ?string $detail = null,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::ATTENDANCE_ANOMALY;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employee_name' => $this->employeeName,
            'anomaly_type' => $this->anomalyType,
            'date' => $this->date,
            'detail' => $this->detail,
            ...NotificationMessage::make('notification.inapp.attendance_anomaly', ['name' => $this->employeeName, 'type' => $this->anomalyType]),
            'url' => '/attendance',
        ];
    }
}
