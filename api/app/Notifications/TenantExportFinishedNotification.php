<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TenantExport;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the administrator who asked for a data export that it finished, or
 * that it could not be prepared. Not subject to notification preferences:
 * the person asked for this one, and the archive is deleted after
 * TenantExport::EXPIRES_DAYS whether or not they come back for it. The
 * failure reason stays in the log and on the row, not in the message.
 */
class TenantExportFinishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly TenantExport $export,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'tenant_export',
            'export_id' => $this->export->public_id,
            'status' => $this->export->status,
            ...$this->body(),
            'url' => '/settings/data-export',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ready = $this->export->status === TenantExport::READY;

        return (new MailMessage)
            ->subject(__($ready ? 'export.ready_subject' : 'export.failed_subject'))
            ->line($this->body()['message'])
            ->action(__('export.open'), FrontendUrl::forNotifiable($notifiable, '/settings/data-export'));
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function body(): array
    {
        return $this->export->status === TenantExport::READY
            ? NotificationMessage::make('export.ready_body', ['days' => TenantExport::EXPIRES_DAYS])
            : NotificationMessage::make('export.failed_body');
    }
}
