<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionApprovedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceCorrection $correction,
        private readonly bool $approved = true,
    ) {}

    protected function preferenceType(): string
    {
        return 'attendance_correction';
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
        $action = $this->approved ? 'approved' : 'rejected';

        // Corrections have no own `date`; derive it from the record being corrected.
        // loadMissing keeps this safe under preventLazyLoading when the model arrives unhydrated.
        $this->correction->loadMissing('attendanceRecord');
        $date = $this->correction->attendanceRecord?->date;

        return [
            'correction_id' => $this->correction->public_id,
            'date' => $date?->format('Y-m-d'),
            'action' => $action,
            'message' => "Your attendance correction for {$date?->format('M d')} has been {$action}",
        ];
    }
}
