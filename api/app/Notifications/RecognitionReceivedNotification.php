<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Recognition;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use App\Support\VoiceLabels;
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
            'message' => $this->line(),
            'url' => '/voice?tab=recognition',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('voice.notify.recognised_subject'))
            ->line($this->line())
            ->line('"'.$this->recognition->message.'"')
            ->action(__('voice.notify.open'), FrontendUrl::forNotifiable($notifiable, '/voice?tab=recognition'));
    }

    private function line(): string
    {
        return __('voice.notify.recognised', [
            'name' => $this->recognition->from->name ?? '',
            'value' => VoiceLabels::pick(__('voice.value'), $this->recognition->value),
        ]);
    }
}
