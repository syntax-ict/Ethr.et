<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\PayrollEntry;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Notifications\Contracts\BulkMail;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use App\Support\NotificationTemplates;
use App\Support\ReaderDates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipAvailableNotification extends Notification implements BulkMail
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly PayrollEntry $entry,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::PAYSLIP_AVAILABLE;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database', 'mail'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'period' => $this->entry->payrollRun?->period_label,
            'net_cents' => $this->entry->net_cents,
            ...NotificationMessage::make('notification.inapp.payslip_available', ['period' => ReaderDates::periodValue($this->entry->payrollRun)], dates: ['period' => 'period'], reader: ReaderDates::for($notifiable)),
            'url' => '/payroll/payslips',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return NotificationTemplates::mail('payslip_available', $this->entry->tenant_id, [
            'employee_name' => $this->entry->employee?->name,
            'period' => ReaderDates::for($notifiable)->periodOf($this->entry->payrollRun),
            // Convention 3's display format, `X,XXX.XX ETB`.
            'net_amount' => number_format(((int) $this->entry->net_cents) / 100, 2).' ETB',
        ])->action(__('notification.view_payslip'), FrontendUrl::forNotifiable($notifiable, '/payroll/payslips'));
    }
}
