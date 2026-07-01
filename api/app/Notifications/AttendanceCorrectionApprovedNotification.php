<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceCorrectionApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly AttendanceCorrection $correction,
        private readonly bool $approved = true,
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
        $action = $this->approved ? 'approved' : 'rejected';

        return [
            'correction_id' => $this->correction->public_id,
            'date' => $this->correction->date->format('Y-m-d'),
            'action' => $action,
            'message' => "Your attendance correction for {$this->correction->date->format('M d')} has been {$action}",
        ];
    }
}
