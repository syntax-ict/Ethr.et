<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollEntry;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipAvailableNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly PayrollEntry $entry,
    ) {}

    protected function preferenceType(): string
    {
        return 'payslip_available';
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'period' => $this->entry->payrollRun?->period_label,
            'net_cents' => $this->entry->net_cents,
            'message' => 'Your payslip is available for '.($this->entry->payrollRun?->period_label ?? 'this period'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Both lines carry a :period placeholder and were called without it,
        // so the subject read "Your Payslip Is Ready — :period".
        $replace = ['period' => (string) $this->entry->payrollRun?->period_label];

        return (new MailMessage)
            ->subject(__('notification.payslip_available_subject', $replace))
            ->line(__('notification.payslip_available_body', $replace))
            ->action(__('notification.view_payslip'), FrontendUrl::to('/payroll/payslips'));
    }
}
