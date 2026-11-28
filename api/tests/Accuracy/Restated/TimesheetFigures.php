<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Services\Payroll\OvertimeCalculator;
use App\Services\Report\TimesheetReport;
use Carbon\Carbon;

/**
 * The summary columns of the timesheet outputs, and what the ledger's totals
 * become under each output's own stated format.
 *
 * The format, stated by TimesheetReport::figures() and TimesheetPdf::figures():
 * days and minutes are whole numbers; hours are minutes divided by 60 and
 * rounded to ONE decimal place, printed with a trailing ".0" dropped ("7", not
 * "7.0"). A footer is not covered by that wording, so a total is compared in the
 * way that follows from it: the ledger's minutes of everyone added first, then
 * the same format applied once.
 *
 * Static only: Pest loads every test file into one process.
 */
final class TimesheetFigures
{
    /**
     * Column key => how the ledger's totals produce it: 'days' (an integer
     * total), 'hours' (minutes through the hours format), or 'none' (a figure
     * the ledger does not hold: it comes from payroll's own limit functions).
     * The order is the order the PDF and the workbook print them in.
     */
    public const COLUMNS = [
        'work_days' => ['days', 'scheduled_days'],
        'present_days' => ['days', 'present_days'],
        'late_days' => ['days', 'late_days'],
        'absent_days' => ['days', 'absent_days'],
        'leave_days' => ['days', 'leave_days'],
        'holiday_days' => ['days', 'holiday_days'],
        'rest_days' => ['days', 'rest_days'],
        'missing_days' => ['days', 'missing_days'],
        'early_days' => ['days', 'early_days'],
        'payable_days' => ['none', null],
        'late_minutes' => ['days', 'late_minutes'],
        'early_minutes' => ['days', 'early_minutes'],
        'scheduled_hours' => ['hours', 'scheduled_minutes'],
        'worked_hours' => ['hours', 'worked_minutes'],
        'short_hours' => ['hours', 'short_minutes'],
    ];

    /** The columns after the rate columns, which the ledger does not hold. */
    public const TRAILING = ['overtime_unapproved_hours', 'overtime_limit_days'];

    /** Minutes as the outputs print hours: one decimal, a trailing ".0" dropped. */
    public static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format(round($minutes / 60, 1), 1, '.', ''), '0'), '.');
    }

    /**
     * The overtime columns: one per distinct rate the organisation pays, in rate
     * order, each with the overtime types that share it (holiday and holiday at night
     * share 2.5), read from the rates payroll itself uses.
     *
     * @return list<array{rate: float, label: string, types: list<string>}>
     */
    public static function rateColumns(int $tenantId, Carbon $from): array
    {
        $rates = app(OvertimeCalculator::class)->ratesFor($tenantId, $from);
        $columns = [];
        foreach (array_keys(TimesheetReport::OVERTIME_FIELDS) as $type) {
            $key = (string) $rates[$type];
            $columns[$key] ??= ['rate' => (float) $rates[$type], 'types' => []];
            $columns[$key]['types'][] = $type;
        }
        $columns = array_values($columns);
        usort($columns, static fn (array $a, array $b): int => $a['rate'] <=> $b['rate']);

        return array_map(static fn (array $c): array => $c + ['label' => (string) __('report.timesheet.overtime_rate', ['rate' => $c['rate']], 'en')], $columns);
    }

    /**
     * The compared columns in print order, with their English heading: the layout
     * both the PDF and the workbook print.
     *
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return list<array{key: string, label: string}>
     */
    public static function layout(array $rateColumns): array
    {
        $columns = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $columns[] = ['key' => $key, 'label' => self::heading($key)];
        }
        foreach ($rateColumns as $column) {
            $columns[] = ['key' => 'rate:'.$column['rate'], 'label' => $column['label']];
        }
        foreach (self::TRAILING as $key) {
            $columns[] = ['key' => $key, 'label' => self::heading($key)];
        }

        return $columns;
    }

    public static function heading(string $key): string
    {
        return $key === 'payable_days'
            ? (string) __('report.timesheet.payable_days', [], 'en')
            : (string) __('report.fields.'.$key, [], 'en');
    }

    /**
     * What each compared column must read for people whose ledger totals are given:
     * one person, or everyone added together. Key => the printed text. A column the
     * ledger does not hold is left out.
     *
     * @param  list<array<string, mixed>>  $ledgerTotals  TimesheetLedger totals, one per person
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return array<string, string>
     */
    public static function expected(array $ledgerTotals, array $rateColumns): array
    {
        $sum = static fn (string $field): int => (int) array_sum(array_column($ledgerTotals, $field));
        $expected = [];
        foreach (self::COLUMNS as $key => [$kind, $field]) {
            if ($kind === 'days') {
                $expected[$key] = (string) $sum($field);
            } elseif ($kind === 'hours') {
                $expected[$key] = self::hours($sum($field));
            }
        }
        foreach ($rateColumns as $column) {
            $minutes = 0;
            foreach ($ledgerTotals as $totals) {
                foreach ($column['types'] as $type) {
                    $minutes += (int) ($totals['overtime'][$type] ?? 0);
                }
            }
            $expected['rate:'.$column['rate']] = self::hours($minutes);
        }

        return $expected;
    }
}
