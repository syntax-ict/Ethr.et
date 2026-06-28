<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PayrollEntry $entry,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'period' => $this->entry->payrollRun?->period_label,
            'net_cents' => $this->entry->net_cents,
            'message' => 'Your payslip is available for ' . ($this->entry->payrollRun?->period_label ?? 'this period'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('notification.payslip_available_subject'))
            ->line(__('notification.payslip_available_body'))
            ->action(__('notification.view_payslip'), url('/payslips'));
    }
}
