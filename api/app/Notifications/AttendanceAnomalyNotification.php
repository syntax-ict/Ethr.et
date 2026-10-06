<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
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

    protected function preferenceType(): string
    {
        return 'attendance_anomaly';
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employee_name' => $this->employeeName,
            'anomaly_type' => $this->anomalyType,
            'date' => $this->date,
            'detail' => $this->detail,
            'message' => "Attendance anomaly detected for {$this->employeeName}: {$this->anomalyType}",
        ];
    }
}
