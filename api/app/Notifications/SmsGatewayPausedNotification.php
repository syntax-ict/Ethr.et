<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use App\Support\ReaderDates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Told to the platform's super admins once, when a gateway has refused enough
 * real messages in a row that SMS stops being offered for a while.
 *
 * Not filed under a notification preference: sign-in codes are going by email
 * only until someone looks, and a toggle that silenced this would hide that.
 * Everything it carries is plain values (no model), and the response in it was
 * stripped of the recipient's number before it got here.
 */
class SmsGatewayPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const PATH = '/admin/sms';

    private const RESPONSE_LIMIT = 300;

    public function __construct(
        private readonly string $slot,
        private readonly ?string $label,
        private readonly int $failures,
        private readonly ?int $status,
        private readonly ?string $response,
        private readonly string $pausedUntil,
    ) {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sms_gateway_paused',
            'slot' => $this->slot,
            ...NotificationMessage::make('sms.notify.in_app', ['failures' => $this->failures]),
            'url' => self::PATH,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dates = ReaderDates::for($notifiable);
        $name = $this->label !== null && $this->label !== '' ? $this->label : $this->slot;

        $mail = (new MailMessage)
            ->subject(__('sms.notify.subject'))
            ->line(__('sms.notify.intro', ['gateway' => $name, 'slot' => $this->slot, 'failures' => $this->failures]))
            ->line(__('sms.notify.status', ['status' => $this->status === null ? __('sms.notify.no_answer') : (string) $this->status]));

        if ($this->response !== null && $this->response !== '') {
            $mail->line(__('sms.notify.response', ['response' => Str::limit($this->response, self::RESPONSE_LIMIT)]));
        }

        return $mail
            ->line(__('sms.notify.retry', ['time' => $dates->dateTime($this->pausedUntil)]))
            ->line(__('sms.notify.meanwhile'))
            ->action(__('sms.notify.open'), FrontendUrl::platform(self::PATH));
    }
}
