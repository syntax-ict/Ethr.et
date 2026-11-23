<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\OvertimeRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use App\Support\ReaderDates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Someone asked to work overtime and this approver may decide it. */
class OvertimeRequestedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly OvertimeRequest $request,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::OVERTIME_REQUEST;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        $this->request->loadMissing('employee');

        return [
            'overtime_request_id' => $this->request->public_id,
            'employee_name' => $this->request->employee?->name,
            'date' => $this->request->date->format('Y-m-d'),
            ...NotificationMessage::make('notification.inapp.overtime_requested', [
                'name' => (string) $this->request->employee?->name,
                'date' => $this->request->date->format('Y-m-d'),
                'hours' => round($this->request->planned_minutes / 60, 1),
            ], dates: ['date' => 'date'], reader: ReaderDates::for($notifiable)),
            'url' => '/approvals',
        ];
    }
}
