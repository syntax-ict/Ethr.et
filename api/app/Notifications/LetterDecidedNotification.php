<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EmploymentLetterRequest;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the employee: their letter was issued, or declined with the reason. */
class LetterDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly EmploymentLetterRequest $letter) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'letter_id' => $this->letter->public_id,
            'status' => $this->letter->status,
            ...$this->line(),
            'url' => '/profile/documents',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('letters.mail_subject_decided'))
            ->line($this->line()['message'])
            ->action(__('letters.open'), FrontendUrl::forNotifiable($notifiable, '/profile/documents'));
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function line(): array
    {
        return $this->letter->status === EmploymentLetterRequest::ISSUED
            ? NotificationMessage::make('letters.issued')
            : NotificationMessage::make('letters.rejected', ['reason' => (string) $this->letter->rejection_reason]);
    }
}
