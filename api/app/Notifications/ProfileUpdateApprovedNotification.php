<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Covers both outcomes of a profile-update review. The employee needs to know a
 * rejection at least as much as an approval — without it, a declined bank-account
 * change looks identical to one that is still waiting.
 */
class ProfileUpdateApprovedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly string $field,
        private readonly bool $approved,
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
            'field' => $this->field,
            'approved' => $this->approved,
            ...NotificationMessage::make($this->approved
                ? 'notification.profile_update_approved_body'
                : 'notification.profile_update_rejected_body', ['field' => $this->field]),
            'url' => '/profile',
        ];
    }
}
