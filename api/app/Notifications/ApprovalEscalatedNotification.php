<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A step that names one person has waited past its time limit, told to that
 * person's supervisor or, without one, to HR (ApprovalEngine::escalateIfOverdue).
 * Under the approval-reminder preference: it is the same kind of nudge, sent
 * one level up.
 */
class ApprovalEscalatedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly string $requestType,
        private readonly string $employeeName,
        private readonly string $approverName,
        private readonly int $hours,
    ) {}

    protected function preferenceType(): string
    {
        return 'approval_reminder';
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_type' => $this->requestType,
            'hours' => $this->hours,
            ...$this->line(),
            'url' => '/approvals',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notification.approval_escalated_subject'))
            ->line($this->line()['message'])
            ->line(__('notification.approval_escalated_hint'))
            ->action(__('notification.approval_escalated_action'), FrontendUrl::forNotifiable($notifiable, '/approvals'));
    }

    /** @return array{message: string, message_key: string, message_params: array<string, scalar|null>, message_labels?: array<string, string>, message_count?: int} */
    private function line(): array
    {
        // Literal keys, so the translation-key sweep can see each one.
        $type = match ($this->requestType) {
            'correction' => 'approval.type_correction',
            'profile_update' => 'approval.type_profile_update',
            'overtime' => 'approval.type_overtime',
            default => 'approval.type_leave',
        };

        return NotificationMessage::make('notification.inapp.approval_escalated', [
            'type' => $this->requestType,
            'name' => $this->employeeName,
            'approver' => $this->approverName,
            'hours' => $this->hours,
        ], ['type' => $type]);
    }
}
