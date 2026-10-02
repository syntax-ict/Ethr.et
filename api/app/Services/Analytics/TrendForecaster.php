<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use DateTimeImmutable;

/**
 * A plain ordinary-least-squares linear regression over an evenly-spaced
 * historical series, projected forward N points. Deliberately not a
 * black-box model — every number here is reproducible by hand from the
 * inputs, matching the platform's policy that anything labelled a forecast
 * stays deterministic, explainable, and free of a paid API dependency.
 *
 * This is a trend line, not a demand-planning system: it assumes the recent
 * trend continues linearly, which is a reasonable first approximation for a
 * dashboard sparkline and a poor one for anything load-bearing. Both the
 * backend response and the frontend label it "projected" for that reason.
 */
final class TrendForecaster
{
    /**
     * @param  array<int, int|float>  $values  Historical series, oldest first.
     * @param  array<int, string>  $labels  Matching period labels (e.g. "2026-05" or a period name).
     * @return array<int, array{label: string, value: float}> The next $periods projected points.
     */
    public static function project(array $values, array $labels, int $periods): array
    {
        $values = array_values($values);
        $n = count($values);

        if ($n < 2) {
            return [];
        }

        // x = 0..n-1 over the historical points; standard least-squares slope/intercept.
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumXX = 0;

        foreach ($values as $i => $y) {
            $sumX += $i;
            $sumY += $y;
            $sumXY += $i * $y;
            $sumXX += $i * $i;
        }

        $denominator = ($n * $sumXX) - ($sumX * $sumX);
        $slope = $denominator !== 0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denominator : 0;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $lastLabel = $labels[$n - 1] ?? (string) ($n - 1);
        $projected = [];

        for ($step = 1; $step <= $periods; $step++) {
            $x = $n - 1 + $step;
            $value = $intercept + ($slope * $x);

            $projected[] = [
                'label' => self::nextLabel($lastLabel, $step),
                // Never project a negative headcount/currency value.
                'value' => round(max(0, $value), 2),
            ];
        }

        return $projected;
    }

    /**
     * Advances a "YYYY-MM" or a "September 2026" label by $step months, in the
     * same shape. Any other label is returned with a generic "+N" suffix
     * instead of guessing its format.
     *
     * The month-name shape is what `PayrollEngine` writes as every run's
     * `period_label` (`$periodStart->format('F Y')`), so it is the shape the
     * payroll forecast always receives — and with only "YYYY-MM" recognised,
     * every projected payroll bar read "September 2026 +1" (audit N34).
     */
    private static function nextLabel(string $lastLabel, int $step): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $lastLabel, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2] + $step;

            $year += intdiv($month - 1, 12);
            $month = (($month - 1) % 12) + 1;

            return sprintf('%04d-%02d', $year, $month);
        }

        // PHP parses and prints `F` in English whatever the locale, so this
        // round-trips exactly what PayrollEngine wrote. The round-trip check
        // refuses anything createFromFormat() merely tolerated.
        $month = DateTimeImmutable::createFromFormat('!F Y', $lastLabel);
        if ($month !== false && $month->format('F Y') === $lastLabel) {
            return $month->modify("+{$step} months")->format('F Y');
        }

        return "{$lastLabel} +{$step}";
    }
}
