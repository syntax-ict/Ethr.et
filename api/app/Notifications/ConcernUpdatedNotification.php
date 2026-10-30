<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Concern;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
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
            ...$this->message(),
            'url' => $this->link(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('voice.notify.updated_subject'))
            ->line($this->message()['message'])
            ->action(__('voice.notify.open'), FrontendUrl::forNotifiable($notifiable, $this->link()));
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function message(): array
    {
        $params = [
            'subject' => $this->concern->subject,
            'status' => $this->concern->status,
        ];
        $labels = ['status' => 'voice.status.'.$this->concern->status];

        return match ($this->event) {
            'hr_replied' => NotificationMessage::make('voice.notify.hr_replied', $params, $labels),
            'reporter_replied' => NotificationMessage::make('voice.notify.reporter_replied', $params, $labels),
            default => NotificationMessage::make('voice.notify.status_changed', $params, $labels),
        };
    }

    private function link(): string
    {
        return $this->event === 'reporter_replied' ? '/voice/insights' : '/voice?tab=speak-up';
    }
}
