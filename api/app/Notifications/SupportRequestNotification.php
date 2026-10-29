<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupportRequest;
use App\Services\Support\SupportDesk;
use App\Support\FrontendUrl;
use App\Support\VoiceLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * One step of a support request, told to whoever the next move belongs to.
 *
 * Not filed under a notification preference: it answers something the person
 * asked, or asks them to answer someone, and a toggle that silenced it would
 * leave a request waiting with nobody told.
 *
 * `$audience` decides where the link leads: the organisation's own help page,
 * or the platform console for ETHR.
 */
class SupportRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENTS = ['received', 'escalated', 'requester_replied', 'answered', 'passed_to_ethr', 'resolved'];

    public function __construct(
        private readonly SupportRequest $request,
        private readonly string $event,
        private readonly string $audience,
        private readonly ?string $excerpt = null,
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
            'type' => 'support_'.$this->event,
            'support_request_public_id' => $this->request->public_id,
            'message' => $this->line(),
            'url' => $this->path(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->line())
            ->line($this->line());

        if ($this->excerpt !== null && $this->excerpt !== '') {
            $mail->line('"'.Str::limit($this->excerpt, 400).'"');
        }

        $url = $this->audience === SupportDesk::VIEWER_ETHR
            ? FrontendUrl::platform($this->path())
            : FrontendUrl::forNotifiable($notifiable, $this->path());

        return $mail->action(__('support.notify.open'), $url);
    }

    /**
     * Every key spelled out: MissingPunchNotificationTest reads each __()
     * call literally to prove both languages carry it, and a key built by
     * concatenation is one it cannot check.
     */
    private function line(): string
    {
        $replace = ['category' => VoiceLabels::pick(__('support.category'), $this->request->category)];

        return match ($this->event) {
            'received' => __('support.notify.received', $replace),
            'escalated' => __('support.notify.escalated', $replace),
            'requester_replied' => __('support.notify.requester_replied', $replace),
            'passed_to_ethr' => __('support.notify.passed_to_ethr', $replace),
            'resolved' => __('support.notify.resolved', $replace),
            default => __('support.notify.answered', $replace),
        };
    }

    private function path(): string
    {
        $base = $this->audience === SupportDesk::VIEWER_ETHR ? '/admin/support' : '/support';

        return $base.'?request='.$this->request->public_id;
    }
}
