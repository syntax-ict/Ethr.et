<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Concerns\LeaveTemplateValues;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use App\Support\NotificationTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Approved leave cancelled or ended early by HR (`LeaveRecallService`).
 * `{days}` is what the leave counts now: 0 when it was cancelled.
 */
class LeaveChangedNotification extends Notification
{
    use LeaveTemplateValues, Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
        private readonly float $days,
        private readonly string $reason,
    ) {}

    protected function preferenceType(): string
    {
        return 'leave_changed';
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
            'leave_request_id' => $this->leaveRequest->public_id,
            'leave_type' => $this->leaveRequest->leaveType?->name,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'status' => $this->leaveRequest->status->value,
            ...NotificationMessage::make('notification.inapp.leave_changed'),
            'url' => '/leave',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return NotificationTemplates::mail(
            'leave_changed',
            $this->leaveRequest->tenant_id,
            [
                ...$this->leaveTemplateValues($this->leaveRequest, $notifiable),
                'days' => rtrim(rtrim(number_format($this->days, 2, '.', ''), '0'), '.'),
                'reason' => $this->reason,
            ],
        );
    }
}
