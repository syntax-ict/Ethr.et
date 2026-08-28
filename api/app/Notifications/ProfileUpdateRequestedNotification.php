<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\RespectsNotificationPreferences;
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

    protected function preferenceType(): string
    {
        return 'profile_update';
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'employee_public_id' => $this->employee->public_id,
            'employee_name' => $this->employee->name,
            'fields' => $this->fields,
            'message' => __('notification.profile_update_requested_body', [
                'name' => $this->employee->name,
            ]),
        ];
    }
}
