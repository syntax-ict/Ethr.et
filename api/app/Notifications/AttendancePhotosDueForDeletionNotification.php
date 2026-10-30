<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use App\Support\TenantTime;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells HR or a department manager that attendance selfies they can see will
 * be deleted after the review window, and where to keep any that an
 * unresolved complaint still needs.
 *
 * Not subject to notification preferences: it is the notice the retention
 * policy promises before a photo is deleted, and muting it would let photos
 * go unseen.
 */
class AttendancePhotosDueForDeletionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $count,
        private readonly CarbonInterface $deleteAfter,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'attendance_photos_due_for_deletion',
            'count' => $this->count,
            'delete_after' => $this->deleteAfter->toIso8601String(),
            ...$this->message($notifiable),
            'url' => '/attendance/photos?status=review',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('attendance.photo_retention.subject'))
            ->line($this->message($notifiable)['message'])
            ->line(__('attendance.photo_retention.keep_it'))
            ->action(
                __('attendance.photo_retention.action'),
                FrontendUrl::forNotifiable($notifiable, '/attendance/photos?status=review'),
            );
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function message(object $notifiable): array
    {
        $tenant = $notifiable instanceof User && $notifiable->tenant instanceof Tenant ? $notifiable->tenant : null;
        $when = $this->deleteAfter->copy()->setTimezone(TenantTime::zone($tenant))->format('Y-m-d H:i');

        return NotificationMessage::make('attendance.photo_retention.message', [
            'count' => $this->count,
            'when' => $when,
        ], count: $this->count);
    }
}
