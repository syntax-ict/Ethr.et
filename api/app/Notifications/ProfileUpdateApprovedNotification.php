<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
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
            'field' => $this->field,
            'approved' => $this->approved,
            'message' => __($this->approved
                ? 'notification.profile_update_approved_body'
                : 'notification.profile_update_rejected_body', ['field' => $this->field]),
        ];
    }
}
