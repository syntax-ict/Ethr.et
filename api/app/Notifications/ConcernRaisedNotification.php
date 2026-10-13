<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Concern;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use App\Support\VoiceLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells HR a concern was raised. It names the category and subject only -
 * never who raised it; HR opens it in the app.
 */
class ConcernRaisedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(private readonly Concern $concern) {}

    protected function preferenceType(): string
    {
        return 'employee_voice';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'concern_raised',
            'concern_public_id' => $this->concern->public_id,
            'message' => __('voice.notify.raised', ['category' => VoiceLabels::pick(__('voice.category'), $this->concern->category)]),
            'url' => '/voice/insights',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('voice.notify.raised_subject'))
            ->line(__('voice.notify.raised', ['category' => VoiceLabels::pick(__('voice.category'), $this->concern->category)]))
            ->action(__('voice.notify.open'), FrontendUrl::forNotifiable($notifiable, '/voice/insights'));
    }
}
