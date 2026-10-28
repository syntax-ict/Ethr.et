<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceDevice;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The employee's phone was approved, not approved, or revoked, with the
 * approver's reason when they gave one. It opens the punch screen, where a
 * revoked or refused phone can be registered again.
 */
class AttendanceDeviceDecidedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceDevice $device,
        private readonly string $outcome,
        private readonly ?string $reason,
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
        $key = match ($this->outcome) {
            AttendanceDevice::ACTIVE => 'phone_approved',
            AttendanceDevice::REJECTED => 'phone_rejected',
            default => 'phone_revoked',
        };
        $reason = trim((string) $this->reason);

        return [
            'device_id' => $this->device->public_id,
            'action' => $this->outcome,
            'reason' => $reason === '' ? null : $reason,
            'message' => $reason === '' || $key === 'phone_approved'
                ? __('notification.inapp.'.$key)
                : __('notification.inapp.'.$key.'_reason', ['reason' => $reason]),
            'url' => '/attendance/mobile',
        ];
    }
}
