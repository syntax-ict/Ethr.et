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
 * Something happened on a concern: HR replied or changed its status (to its
 * named reporter), or the reporter replied (to HR). An anonymous reporter has
 * no account on file to notify; they check with their code.
 */
class ConcernUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly Concern $concern,
        private readonly string $event,
    ) {}

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
            'type' => 'concern_'.$this->event,
            'concern_public_id' => $this->concern->public_id,
            'message' => $this->message(),
            'url' => $this->link(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('voice.notify.updated_subject'))
            ->line($this->message())
            ->action(__('voice.notify.open'), FrontendUrl::forNotifiable($notifiable, $this->link()));
    }

    private function message(): string
    {
        $params = [
            'subject' => $this->concern->subject,
            'status' => VoiceLabels::pick(__('voice.status'), $this->concern->status),
        ];

        return match ($this->event) {
            'hr_replied' => __('voice.notify.hr_replied', $params),
            'reporter_replied' => __('voice.notify.reporter_replied', $params),
            default => __('voice.notify.status_changed', $params),
        };
    }

    private function link(): string
    {
        return $this->event === 'reporter_replied' ? '/voice/insights' : '/voice?tab=speak-up';
    }
}
