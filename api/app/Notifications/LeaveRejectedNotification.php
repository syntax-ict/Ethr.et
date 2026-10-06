<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Concerns\LeaveTemplateValues;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRejectedNotification extends Notification
{
    use LeaveTemplateValues, Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
    ) {}

    protected function preferenceType(): string
    {
        return 'leave_rejected';
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
            'reason' => $this->leaveRequest->rejected_reason,
            'message' => 'Your leave request has been rejected',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return NotificationTemplates::mail(
            'leave_rejected',
            $this->leaveRequest->tenant_id,
            $this->leaveTemplateValues($this->leaveRequest),
        );
    }
}
