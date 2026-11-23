<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\LeaveRequest;
use App\Notifications\Concerns\LeaveTemplateValues;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
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

    protected function preferenceType(): NotificationType
    {
        return NotificationType::LEAVE_REJECTED;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'leave_request_id' => $this->leaveRequest->public_id,
            'leave_type' => $this->leaveRequest->leaveType?->name,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'reason' => $this->leaveRequest->rejected_reason,
            ...NotificationMessage::make('notification.inapp.leave_rejected'),
            'url' => '/leave',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return NotificationTemplates::mail(
            'leave_rejected',
            $this->leaveRequest->tenant_id,
            $this->leaveTemplateValues($this->leaveRequest, $notifiable),
        );
    }
}
