<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AlertThreshold;
use Illuminate\Support\Collection;

/**
 * Phase 6.7 — evaluates tenant-configured AlertThreshold rows against the
 * same `ExecutiveDashboardService::overview()`/`complianceSnapshot()` data
 * the dashboard and the digest email (6.6) already compute, so a triggered
 * alert is always backed by a real, currently-visible number — never a
 * fabricated score. Deliberately a small, fixed metric catalog rather than a
 * free-text field: an admin picks from what the platform actually measures,
 * so a threshold can never reference a typo'd or nonexistent metric.
 */
final class AlertEvaluator
{
    /**
     * metric key => [dotted path into overview()+complianceSnapshot() merged
     * together, translation key for the label].
     *
     * @var array<string, array{path: string, label: string}>
     */
    public const METRICS = [
        'turnover_rate' => ['path' => 'turnover.rate', 'label' => 'dashboard.alert_metric_turnover_rate'],
        'attendance_rate_today' => ['path' => 'attendance_rate.today', 'label' => 'dashboard.alert_metric_attendance_rate'],
        'expiring_documents_count' => ['path' => 'expiring_documents.count', 'label' => 'dashboard.alert_metric_expiring_documents'],
        'probation_overdue_count' => ['path' => 'probation_overdue.count', 'label' => 'dashboard.alert_metric_probation_overdue'],
        'unused_leave_count' => ['path' => 'unused_leave.count', 'label' => 'dashboard.alert_metric_unused_leave'],
    ];

    public const OPERATORS = ['gt', 'lt'];

    /**
     * @param  array<string, mixed>  $overview  ExecutiveDashboardService::overview()
     * @param  array<string, mixed>  $compliance  ExecutiveDashboardService::complianceSnapshot()
     * @param  Collection<int, AlertThreshold>  $thresholds
     * @return array<int, array{public_id: string, metric: string, operator: string, threshold_value: float, current_value: float, severity: string}>
     */
    public function evaluate(array $overview, array $compliance, Collection $thresholds): array
    {
        $data = array_merge($overview, $compliance);
        $triggered = [];

        foreach ($thresholds as $threshold) {
            $definition = self::METRICS[$threshold->metric] ?? null;

            if ($definition === null) {
                continue;
            }

            $current = $this->extract($data, $definition['path']);

            if ($current === null) {
                continue;
            }

            if ($this->breaches($current, $threshold->operator, $threshold->threshold_value)) {
                $triggered[] = [
                    'public_id' => $threshold->public_id,
                    'metric' => $threshold->metric,
                    'operator' => $threshold->operator,
                    'threshold_value' => $threshold->threshold_value,
                    'current_value' => $current,
                    'severity' => $threshold->severity,
                ];
            }
        }

        return $triggered;
    }

    private function breaches(float $current, string $operator, float $thresholdValue): bool
    {
        return match ($operator) {
            'gt' => $current > $thresholdValue,
            'lt' => $current < $thresholdValue,
            default => false,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function extract(array $data, string $path): ?float
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
