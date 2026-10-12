<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AttendanceRecord;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee their open check-in was closed for them at the end of
 * their shift, and how to correct it if they actually left at another time.
 */
class AttendanceAutoCheckedOutNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly AttendanceRecord $record,
        private readonly string $shiftEnd, // tenant wall clock, H:i
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
            'type' => 'auto_checkout',
            'record_public_id' => $this->record->public_id,
            'date' => $this->record->date?->format('Y-m-d'),
            'shift_end' => $this->shiftEnd,
            'message' => __('attendance.auto_checkout.message', [
                'date' => $this->record->date?->format('Y-m-d'),
                'time' => $this->shiftEnd,
            ]),
            'url' => '/attendance/corrections',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('attendance.auto_checkout.subject'))
            ->line(__('attendance.auto_checkout.message', [
                'date' => $this->record->date?->format('Y-m-d'),
                'time' => $this->shiftEnd,
            ]))
            ->line(__('attendance.auto_checkout.correct_it'))
            ->action(
                __('attendance.auto_checkout.action'),
                FrontendUrl::forNotifiable($notifiable, '/attendance/corrections'),
            );
    }
}
