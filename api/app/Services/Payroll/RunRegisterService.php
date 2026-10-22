<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Support\Csv;

/**
 * The payroll register: one row per employee with every figure that makes up
 * their pay, and a totals row — what an accountant reconciles a run against.
 *
 * The bank file carries net pay only and the journal carries totals only, so
 * there was no single document showing, per employee, basic → overtime →
 * allowances → gross → taxable → tax → pension (both shares) → other
 * deductions → net. Every figure is read from the stored entry and its
 * immutable calculation_log (convention #11), never recomputed, so the
 * register shows what was actually paid. Its totals equal the run's totals
 * and the accounting journal's lines (PayrollRegisterTest asserts both).
 */
final class RunRegisterService
{
    public const COLUMNS = [
        'basic_cents', 'overtime_cents', 'allowances_cents', 'gross_cents', 'taxable_cents',
        'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents',
        'loan_cents', 'cost_sharing_cents', 'net_cents',
    ];

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, int>,
     *     pension_rates: array{employee: list<float>, employer: list<float>},
     *     basis: array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>},
     *     pension_exemptions: array{count: int, by_reason: array<string, int>, employees: list<array{employee_public_id: mixed, employee_name: mixed, reason: string}>},
     *     overtime_limits: array{count: int, daily_limit_minutes: int|null, legal_daily_limit_minutes: int|null, legal_weekly_limit_minutes: int|null, employees: list<array{employee_public_id: mixed, employee_name: mixed, overtime_minutes: int, days_over_daily_limit: int, days_over_legal_daily_limit: int, weeks_over_legal_weekly_limit: int, longest_week_minutes: int, days_over_approved: int}>},
     * }
     */
    public function register(PayrollRun $run): array
    {
        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->row($entry);
        }

        $totals = [];
        foreach (self::COLUMNS as $column) {
            $totals[$column] = (int) array_sum(array_column($rows, $column));
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'pension_rates' => self::ratesUsed($rows),
            'basis' => self::basisUsed($rows),
            'pension_exemptions' => self::exemptions($rows),
            'overtime_limits' => self::overtimeLimits($rows),
        ];
    }

    /**
     * Who worked overtime past the organisation's daily limit, the legal
     * daily or weekly one, or what was approved in advance, from each
     * entry's log. Shown on the run page
     * before approval; never a reason to pay less, since overtime worked is
     * owed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, daily_limit_minutes: int|null, legal_daily_limit_minutes: int|null, legal_weekly_limit_minutes: int|null, employees: list<array{employee_public_id: mixed, employee_name: mixed, overtime_minutes: int, days_over_daily_limit: int, days_over_legal_daily_limit: int, weeks_over_legal_weekly_limit: int, longest_week_minutes: int, days_over_approved: int}>}
     */
    public static function overtimeLimits(array $rows): array
    {
        $employees = [];
        foreach ($rows as $row) {
            $own = self::countOf($row['overtime_days_over_daily_limit'] ?? null);
            $legalDays = self::countOf($row['overtime_days_over_legal_daily_limit'] ?? null);
            $weeks = is_array($row['overtime_weeks_over_legal_weekly_limit'] ?? null) ? $row['overtime_weeks_over_legal_weekly_limit'] : [];
            $unapproved = self::countOf($row['overtime_days_over_approved'] ?? null);

            if ($own === 0 && $legalDays === 0 && $weeks === [] && $unapproved === 0) {
                continue;
            }

            $employees[] = [
                'employee_public_id' => $row['employee_public_id'],
                'employee_name' => $row['employee_name'],
                'overtime_minutes' => (int) ($row['overtime_minutes'] ?? 0),
                'days_over_daily_limit' => $own,
                'days_over_legal_daily_limit' => $legalDays,
                'weeks_over_legal_weekly_limit' => count($weeks),
                'longest_week_minutes' => self::longestWeek($weeks),
                'days_over_approved' => $unapproved,
            ];
        }

        return [
            'count' => count($employees),
            'daily_limit_minutes' => self::limitUsed($rows, 'overtime_daily_limit_minutes'),
            'legal_daily_limit_minutes' => self::limitUsed($rows, 'overtime_legal_daily_limit_minutes'),
            'legal_weekly_limit_minutes' => self::limitUsed($rows, 'overtime_legal_weekly_limit_minutes'),
            'employees' => $employees,
        ];
    }

    /** @param  array<mixed>  $weeks */
    private static function longestWeek(array $weeks): int
    {
        $longest = 0;
        foreach ($weeks as $week) {
            if (is_array($week) && is_numeric($week['minutes'] ?? null) && (int) $week['minutes'] > $longest) {
                $longest = (int) $week['minutes'];
            }
        }

        return $longest;
    }

    private static function countOf(mixed $list): int
    {
        return is_array($list) ? count($list) : 0;
    }

    /**
     * An overtime limit the run applied, from the first entry that logged
     * one; null for a run calculated before limits were logged.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function limitUsed(array $rows, string $key): ?int
    {
        foreach ($rows as $row) {
            if (is_int($row[$key] ?? null)) {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Who the run exempted from pension, and why, from each entry's log —
     * shown on the run page and acknowledged at approval, so an exemption is
     * seen by a person before anyone is paid.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, by_reason: array<string, int>, employees: list<array{employee_public_id: mixed, employee_name: mixed, reason: string}>}
     */
    public static function exemptions(array $rows): array
    {
        $employees = [];
        $byReason = [];
        foreach ($rows as $row) {
            if (($row['pension_exempt'] ?? null) !== true) {
                continue;
            }
            $reason = is_string($row['pension_exempt_reason'] ?? null) ? $row['pension_exempt_reason'] : 'unknown';
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            $employees[] = [
                'employee_public_id' => $row['employee_public_id'],
                'employee_name' => $row['employee_name'],
                'reason' => $reason,
            ];
        }

        return ['count' => count($employees), 'by_reason' => $byReason, 'employees' => $employees];
    }

    /**
     * The pension rates the run applied, from its calculation logs — never a
     * constant. Normally one each; two only if a rule changed mid-run.
     * Empty for a run calculated before the rates were logged (2026-10-14).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{employee: list<float>, employer: list<float>}
     */
    public static function ratesUsed(array $rows): array
    {
        $pick = static function (string $key) use ($rows): array {
            $rates = [];
            foreach ($rows as $row) {
                if (($row['pension_exempt'] ?? false) !== true && is_numeric($row[$key] ?? null)) {
                    $rates[] = (float) $row[$key];
                }
            }
            $rates = array_values(array_unique($rates, SORT_REGULAR));
            sort($rates);

            return $rates;
        };

        return ['employee' => $pick('employee_pension_rate'), 'employer' => $pick('employer_pension_rate')];
    }

    /**
     * The rules the run was built on, each once: legal reference, effective
     * date and whether it was the platform's, the organisation's or the
     * fallback.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>}
     */
    public static function basisUsed(array $rows): array
    {
        $collect = static function (string $key) use ($rows): array {
            $seen = [];
            foreach ($rows as $row) {
                $rule = $row[$key] ?? null;
                if (is_array($rule) && isset($rule['legal_reference'])) {
                    $seen[$rule['legal_reference'].'|'.($rule['effective_from'] ?? '')] = [
                        'legal_reference' => $rule['legal_reference'],
                        'effective_from' => $rule['effective_from'] ?? null,
                        'source' => $rule['source'] ?? null,
                    ];
                }
            }

            return array_values($seen);
        };

        return ['pension' => $collect('pension_rule'), 'tax' => $collect('tax_rule')];
    }

    /** "7%", "7% / 8%", or "" when the run did not record its rates. */
    public static function rateLabel(array $rates): string
    {
        return implode(' / ', array_map(static fn (float $r): string => rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.').'%', $rates));
    }

    public function csv(PayrollRun $run): string
    {
        $register = $this->register($run);

        $lines = [Csv::row([
            'Employee Code', 'Employee Name', 'Basic (ETB)', 'Overtime (ETB)', 'Allowances (ETB)',
            'Gross (ETB)', 'Taxable (ETB)', 'Income Tax (ETB)',
            trim('Employee Pension '.self::rateLabel($register['pension_rates']['employee'])).' (ETB)',
            trim('Employer Pension '.self::rateLabel($register['pension_rates']['employer'])).' (ETB)',
            'Loan (ETB)', 'Cost Sharing (ETB)', 'Net Pay (ETB)',
        ])];

        foreach ($register['rows'] as $row) {
            $lines[] = Csv::row([
                $row['employee_code'], $row['employee_name'],
                ...array_map(static fn (string $c): string => Csv::amount($row[$c]), self::COLUMNS),
            ]);
        }

        $lines[] = Csv::row([
            'TOTAL', count($register['rows']).' employees',
            ...array_map(static fn (string $c): string => Csv::amount($register['totals'][$c]), self::COLUMNS),
        ]);

        return implode("\r\n", $lines);
    }

    /**
     * The overtime limit fields come from the log too: flagged and still
     * paid (2026-10-22), and null on entries calculated before the limits
     * were logged.
     *
     * @return array<string, mixed>
     */
    private function row(PayrollEntry $entry): array
    {
        // The `array` cast: read as the loosely-typed attribute so the shape
        // checks below stay honest under analysis.
        $log = $entry->getAttribute('calculation_log');
        $logSteps = is_array($log) && is_array($log['steps'] ?? null) ? $log['steps'] : [];

        /** @var array<string, array<string, mixed>> $steps */
        $steps = [];
        foreach ($logSteps as $step) {
            if (is_array($step) && is_string($step['step'] ?? null)) {
                $steps[$step['step']] = $step;
            }
        }

        /** @var Employee|null $employee */
        $employee = $entry->getRelation('employee');

        $deductions = collect((array) $entry->deductions);
        $allowances = (int) collect((array) $entry->allowances)->sum('amount_cents');

        return [
            'employee_public_id' => $employee?->public_id,
            'employee_code' => $employee === null ? '' : (string) $employee->employee_code,
            'employee_name' => $employee === null ? '' : (string) $employee->name,
            'basic_cents' => (int) $entry->basic_salary_cents,
            'overtime_cents' => (int) ($steps['overtime']['overtime_amount_cents'] ?? 0),
            'allowances_cents' => $allowances,
            'gross_cents' => (int) $entry->gross_cents,
            'taxable_cents' => (int) ($steps['income_tax']['taxable_amount_cents'] ?? $entry->gross_cents),
            'income_tax_cents' => (int) $entry->income_tax_cents,
            'employee_pension_cents' => (int) $entry->employee_pension_cents,
            'employer_pension_cents' => (int) $entry->employer_pension_cents,
            'loan_cents' => (int) $deductions->where('type', 'loan')->sum('amount_cents'),
            'cost_sharing_cents' => (int) $deductions->where('type', 'cost_sharing')->sum('amount_cents'),
            'net_cents' => (int) $entry->net_cents,
            // From the log, as applied (2026-10-14). Null on older entries.
            'pensionable_cents' => (int) ($steps['pension']['pensionable_cents'] ?? $steps['pension']['pensionable_basic_cents'] ?? $entry->basic_salary_cents),
            'pension_exempt' => isset($steps['pension']['exempt']) ? (bool) $steps['pension']['exempt'] : null,
            'pension_exempt_reason' => $steps['pension']['exempt_reason'] ?? null,
            'employee_pension_rate' => isset($steps['pension']['employee_rate']) ? (float) $steps['pension']['employee_rate'] : null,
            'employer_pension_rate' => isset($steps['pension']['employer_rate']) ? (float) $steps['pension']['employer_rate'] : null,
            'pension_rule' => is_array($steps['pension']['rule'] ?? null) ? $steps['pension']['rule'] : null,
            'tax_rule' => is_array($steps['income_tax']['rule'] ?? null) ? $steps['income_tax']['rule'] : null,
            'overtime_minutes' => (int) ($steps['overtime']['overtime_minutes'] ?? 0),
            'overtime_days_over_daily_limit' => is_array($steps['overtime']['limits']['days_over_daily_limit'] ?? null)
                ? array_values($steps['overtime']['limits']['days_over_daily_limit'])
                : null,
            'overtime_days_over_legal_daily_limit' => is_array($steps['overtime']['limits']['days_over_legal_daily_limit'] ?? null)
                ? array_values($steps['overtime']['limits']['days_over_legal_daily_limit'])
                : null,
            'overtime_weeks_over_legal_weekly_limit' => is_array($steps['overtime']['limits']['weeks_over_legal_weekly_limit'] ?? null)
                ? array_values($steps['overtime']['limits']['weeks_over_legal_weekly_limit'])
                : null,
            'overtime_daily_limit_minutes' => isset($steps['overtime']['limits']['daily_limit_minutes'])
                ? (int) $steps['overtime']['limits']['daily_limit_minutes']
                : null,
            'overtime_legal_daily_limit_minutes' => isset($steps['overtime']['limits']['legal_daily_limit_minutes'])
                ? (int) $steps['overtime']['limits']['legal_daily_limit_minutes']
                : null,
            'overtime_legal_weekly_limit_minutes' => isset($steps['overtime']['limits']['legal_weekly_limit_minutes'])
                ? (int) $steps['overtime']['limits']['legal_weekly_limit_minutes']
                : null,
            'overtime_days_over_approved' => is_array($steps['overtime']['limits']['days_over_approved'] ?? null)
                ? array_values($steps['overtime']['limits']['days_over_approved'])
                : null,
        ];
    }
}
