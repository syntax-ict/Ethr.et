<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceRecord;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee a punch of theirs was reviewed and not accepted, with the
 * reviewer's reason and the way to correct it. Nobody loses a day's
 * attendance without being told.
 */
class AttendancePunchRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceRecord $record,
        private readonly string $reason,
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
            'type' => 'punch_rejected',
            'record_public_id' => $this->record->public_id,
            'date' => $this->record->date?->format('Y-m-d'),
            'message' => __('attendance.punch_rejected.message', [
                'date' => $this->record->date?->format('Y-m-d'),
                'reason' => $this->reason,
            ]),
            'url' => '/attendance/corrections',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('attendance.punch_rejected.subject'))
            ->line(__('attendance.punch_rejected.message', [
                'date' => $this->record->date?->format('Y-m-d'),
                'reason' => $this->reason,
            ]))
            ->line(__('attendance.punch_rejected.correct_it'))
            ->action(
                __('attendance.punch_rejected.action'),
                FrontendUrl::forNotifiable($notifiable, '/attendance/corrections'),
            );
    }
}
