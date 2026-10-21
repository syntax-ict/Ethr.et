<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TenantExport;
use App\Support\FrontendUrl;
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
            'message' => $this->body(),
            'url' => '/settings/data-export',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ready = $this->export->status === TenantExport::READY;

        return (new MailMessage)
            ->subject(__($ready ? 'export.ready_subject' : 'export.failed_subject'))
            ->line($this->body())
            ->action(__('export.open'), FrontendUrl::forNotifiable($notifiable, '/settings/data-export'));
    }

    private function body(): string
    {
        return $this->export->status === TenantExport::READY
            ? __('export.ready_body', ['days' => TenantExport::EXPIRES_DAYS])
            : __('export.failed_body');
    }
}
