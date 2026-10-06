<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AnnouncementNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly Announcement $announcement,
    ) {}

    protected function preferenceType(): string
    {
        return 'announcement';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'announcement_id' => $this->announcement->public_id,
            'title' => $this->announcement->title,
            'priority' => $this->announcement->priority,
            'message' => $this->announcement->title,
        ];
    }
}
