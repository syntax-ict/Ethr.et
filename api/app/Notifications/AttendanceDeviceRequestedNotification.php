<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceDevice;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A phone waits for approval: registered away from work, or replacing the
 * person's phone. In the app (and, through the push listener, on subscribed
 * phones), so a request does not sit until someone opens the phones page.
 */
class AttendanceDeviceRequestedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceDevice $device,
        private readonly string $employeeName,
        private readonly bool $replacing,
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
            'device_id' => $this->device->public_id,
            'employee_name' => $this->employeeName,
            'replacing' => $this->replacing,
            'message' => __($this->replacing ? 'notification.inapp.phone_replacement_requested' : 'notification.inapp.phone_requested', ['name' => $this->employeeName]),
            'url' => '/attendance/devices',
        ];
    }
}
