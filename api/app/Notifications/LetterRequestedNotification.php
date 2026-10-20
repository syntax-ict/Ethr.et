<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EmploymentLetterRequest;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To HR: an employee asked for an employment letter. */
class LetterRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly EmploymentLetterRequest $letter,
        private readonly string $employeeName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'letter_id' => $this->letter->public_id,
            'message' => __('letters.requested', ['name' => $this->employeeName]),
            'url' => '/employees/letters',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('letters.mail_subject_requested'))
            ->line(__('letters.requested', ['name' => $this->employeeName]))
            ->action(__('letters.open'), FrontendUrl::forNotifiable($notifiable, '/employees/letters'));
    }
}
