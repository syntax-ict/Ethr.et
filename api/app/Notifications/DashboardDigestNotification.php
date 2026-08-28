<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Analytics\AlertEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 6.6. Delivers the actual KPI numbers, not just "your digest ran" —
 * the same lesson `ScheduledReportNotification` already applied to scheduled
 * reports (see its docblock). Also folds in any Phase 6.7 alert thresholds
 * currently breached, so a digest recipient sees both the routine summary
 * and anything that needs attention in one email.
 */
class DashboardDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $overview  ExecutiveDashboardService::overview()
     * @param  array<string, mixed>  $compliance  ExecutiveDashboardService::complianceSnapshot()
     * @param  array<int, array{metric: string, operator: string, threshold_value: float, current_value: float, severity: string}>  $alerts  AlertEvaluator::evaluate() — Phase 6.7
     */
    public function __construct(
        private readonly string $frequency,
        private readonly array $overview,
        private readonly array $compliance,
        private readonly ?string $branchName = null,
        private readonly array $alerts = [],
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
            'title' => __('dashboard.digest_subject', ['frequency' => $this->frequency]),
            'branch' => $this->branchName,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $headcount = $this->overview['headcount'] ?? [];
        $attendance = $this->overview['attendance_rate'] ?? [];
        $payroll = $this->overview['payroll_summary'] ?? [];
        $turnover = $this->overview['turnover'] ?? [];

        $message = (new MailMessage)
            ->subject(__('dashboard.digest_subject', ['frequency' => $this->frequency]))
            ->line(__('dashboard.digest_greeting', [
                'frequency' => $this->frequency,
                'date' => now()->toFormattedDateString(),
            ]));

        if ($this->branchName !== null) {
            $message->line($this->branchName);
        }

        $message
            ->line(__('dashboard.digest_headcount', [
                'active' => $headcount['active'] ?? 0,
                'total' => $headcount['total'] ?? 0,
            ]))
            ->line(__('dashboard.digest_attendance', [
                'rate' => $attendance['today'] ?? 0,
            ]))
            ->line(__('dashboard.digest_payroll', [
                'amount' => number_format((($payroll['net_cents'] ?? 0)) / 100, 2),
            ]))
            ->line(__('dashboard.digest_turnover', [
                'rate' => $turnover['rate'] ?? 0,
            ]));

        $expiringDocs = $this->compliance['expiring_documents']['count'] ?? null;
        if (is_int($expiringDocs) && $expiringDocs > 0) {
            $message->line(__('dashboard.digest_compliance_documents', ['count' => $expiringDocs]));
        }

        $probationOverdue = $this->compliance['probation_overdue']['count'] ?? null;
        if (is_int($probationOverdue) && $probationOverdue > 0) {
            $message->line(__('dashboard.digest_compliance_probation', ['count' => $probationOverdue]));
        }

        $unusedLeave = $this->compliance['unused_leave']['count'] ?? null;
        if (is_int($unusedLeave) && $unusedLeave > 0) {
            $message->line(__('dashboard.digest_compliance_leave', ['count' => $unusedLeave]));
        }

        foreach ($this->alerts as $alert) {
            $labelKey = AlertEvaluator::METRICS[$alert['metric']]['label'] ?? null;

            $message->line(__('dashboard.digest_alert_triggered', [
                'label' => $labelKey !== null ? __($labelKey) : $alert['metric'],
                'current' => $alert['current_value'],
                'operator' => $alert['operator'] === 'gt' ? '>' : '<',
                'threshold' => $alert['threshold_value'],
            ]));
        }

        return $message->action(
            __('dashboard.digest_view_dashboard'),
            url('/analytics'),
        );
    }
}
