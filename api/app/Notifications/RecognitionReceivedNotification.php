<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Recognition;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an employee a colleague recognised them, and for what. */
class RecognitionReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(private readonly Recognition $recognition) {}

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
            'type' => 'recognition_received',
            'recognition_public_id' => $this->recognition->public_id,
            ...$this->line(),
            'url' => '/voice?tab=recognition',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('voice.notify.recognised_subject'))
            ->line($this->line()['message'])
            ->line('"'.$this->recognition->message.'"')
            ->action(__('voice.notify.open'), FrontendUrl::forNotifiable($notifiable, '/voice?tab=recognition'));
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function line(): array
    {
        return NotificationMessage::make('voice.notify.recognised', [
            'name' => $this->recognition->from->name ?? '',
            'value' => $this->recognition->value,
        ], ['value' => 'voice.value.'.$this->recognition->value]);
    }
}
