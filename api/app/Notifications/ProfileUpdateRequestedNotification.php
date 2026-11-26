<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Employee;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProfileUpdateRequestedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    /**
     * @param  array<int, string>  $fields
     */
    public function __construct(
        private readonly Employee $employee,
        private readonly array $fields,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::PROFILE_UPDATE;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database'], broadcast: true);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'employee_public_id' => $this->employee->public_id,
            'employee_name' => $this->employee->name,
            'fields' => $this->fields,
            ...NotificationMessage::make('notification.profile_update_requested_body', [
                'name' => $this->employee->name,
            ]),
            'url' => '/approvals',
        ];
    }
}
