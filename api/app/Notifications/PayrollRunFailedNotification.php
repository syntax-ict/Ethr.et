<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The counterpart to `PayrollProcessedNotification`, and it deliberately
 * differs from it in two ways.
 *
 * 1. IT DOES NOT USE `RespectsNotificationPreferences`. The success notice does,
 *    under the `payroll_processed` preference, and that is right: a monthly
 *    "payroll ran" message is informational and someone may reasonably mute it.
 *    A crashed run is not informational. Muting the good news must not mute the
 *    bad — and since `ProcessPayrollJob` sets `$tries = 1` on purpose, so that a
 *    failed run is inspected and re-submitted by a human rather than silently
 *    retried, the design already depends on a human finding out.
 *
 * 2. IT SENDS MAIL. The success notice is `database` only (plus broadcast where
 *    Reverb is deployed, which is nowhere), so it reaches whoever opens the
 *    dashboard. That is adequate for good news and useless for bad: the whole
 *    point of this one is reaching someone who is NOT already looking. A monthly
 *    run that crashes could otherwise sit unnoticed for days.
 *
 * The reason is included. As with `DeviceSyncFailedNotification`, the audience
 * decides it: these go to `finance_admin` and `tenant_admin` users of the run's
 * own tenant, who are already entitled to the run's detail — not to the
 * free-text address lists that `ScheduledReportFailedNotification` serves, where
 * the reason is withheld.
 */
class PayrollRunFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PayrollRun $payrollRun,
        private readonly string $reason,
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
            'payroll_run_id' => $this->payrollRun->public_id,
            'period' => $this->payrollRun->period_label,
            'reason' => $this->reason,
            'message' => __('payroll.run_failed_body', [
                'period' => (string) $this->payrollRun->period_label,
                'reason' => $this->reason,
            ]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('payroll.run_failed_subject', ['period' => (string) $this->payrollRun->period_label]))
            ->line(__('payroll.run_failed_body', [
                'period' => (string) $this->payrollRun->period_label,
                'reason' => $this->reason,
            ]))
            ->line(__('payroll.run_failed_next_step'));
    }
}
