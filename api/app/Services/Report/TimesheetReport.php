<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\EmployeeStatus;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\ShiftMatcher;
use App\Services\Attendance\TimesheetLedger;
use App\Services\Payroll\AttendanceReconciliation;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\StatutoryRules;
use App\Services\Payroll\StatutoryRuleSet;
use App\Support\AttendanceRules;
use App\Support\TenantTime;
use Carbon\Carbon;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The monthly timesheet, as the report source and the grid endpoint read it.
 *
 * Both are projections of `TimesheetLedger`, which reads the functions payroll
 * reads. This class chooses the days and the people, runs the ledger in chunks
 * the host can carry, and turns a row's totals into the figures a reader sees.
 * It computes no attendance figure of its own.
 */
final class TimesheetReport
{
    /** People per ledger pass: what a 128 MB host holds while a month of records is read. */
    public const CHUNK = 150;

    /** The longest span a timesheet covers: a Gregorian or an Ethiopian month. */
    public const MAX_DAYS = 31;

    /**
     * The most people one request reads at once. Reading a person for a month
     * costs the ledger about 0.1 second on the loaded development machine
     * (BASELINE 25ba), and the host gives a request 30. Past this the grid
     * still pages through everyone, but its totals and the report source stop
     * here, and say so, until a department, branch or shift narrows the list.
     */
    public const MAX_PEOPLE = 200;

    /** The overtime types, in the order payroll lists them, each with the hours field that carries it. */
    public const OVERTIME_FIELDS = [
        'normal' => 'overtime_normal_hours',
        'night' => 'overtime_night_hours',
        'rest_day' => 'overtime_rest_day_hours',
        'holiday' => 'overtime_holiday_hours',
        'holiday_night' => 'overtime_holiday_night_hours',
    ];

    public function __construct(
        private readonly TimesheetLedger $ledger,
        private readonly StatutoryRules $statutory,
        private readonly OvertimeCalculator $overtimeRates,
        private readonly ShiftMatcher $shifts,
        private readonly AttendanceReconciliation $reconciliation,
        private readonly PayrollEngine $payroll,
    ) {}

    /**
     * The days a request asks for, at most `$maxDays`.
     *
     * `from` and `to` choose them, so a month can be a Gregorian one or an
     * Ethiopian one (Tikimt runs from 11 October to 9 November). `month`
     * (`YYYY-MM`) still works for API callers and saved reports. With neither,
     * the month of `$anchor`. `$stopAt`, when given, ends the span there: the
     * report stops at yesterday, since today is not over, and the grid does not.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}|null null when the span is empty
     */
    public function span(array $filters, Carbon $anchor, int $maxDays, ?Carbon $stopAt = null): ?array
    {
        if (! empty($filters['from'])) {
            $from = Carbon::parse((string) $filters['from'])->startOfDay();
            $to = empty($filters['to']) ? $from->copy()->addDays($maxDays - 1) : Carbon::parse((string) $filters['to'])->startOfDay();
            if ($from->diffInDays($to) >= $maxDays) {
                $to = $from->copy()->addDays($maxDays - 1);
            }
        } else {
            $month = is_string($filters['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['month']) === 1
                ? Carbon::parse($filters['month'].'-01')
                : $anchor->copy()->startOfMonth();
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth()->startOfDay();
        }

        if ($stopAt !== null && $to->greaterThan($stopAt)) {
            $to = $stopAt->copy();
        }

        return $from->greaterThan($to) ? null : [$from, $to];
    }

    /**
     * The people a timesheet lists, in name order, limited to what the viewer
     * may see, and who is on the books at some point in the span. Department
     * and branch take a unit's `public_id`; `employment_status` takes an
     * `EmployeeStatus`.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function employees(int $tenantId, array $filters, User $viewer, Carbon $from, Carbon $to): Builder
    {
        $query = Employee::query()->where('tenant_id', $tenantId);
        UnitFilter::apply($query, $filters);

        // Someone hired after the span, or who left before it, has no day on it:
        // the same window ExpectedDays reads. Without it a thousand people who
        // left years ago fill the size limit and push the people at work off it.
        $query->where(fn (Builder $hired) => $hired->whereNull('hire_date')->orWhereDate('hire_date', '<=', $to->format('Y-m-d')))
            ->where(fn (Builder $left) => $left->whereNull('termination_date')->orWhereDate('termination_date', '>=', $from->format('Y-m-d')));

        $status = $filters['employment_status'] ?? null;
        if (is_string($status) && EmployeeStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $viewer->scopeAccessibleEmployees($query);

        return $query;
    }

    /**
     * The shift each person worked on most of the days: their shift on the
     * timesheet, and what a `shift` filter matches. A day with no shift (the
     * organisation's working week alone) counts for no shift.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @return array<int, Shift|null> employee id => shift
     */
    public function dominantShifts(Collection $employees, Carbon $from, Carbon $to): array
    {
        $dominant = [];

        foreach ($this->shifts->shiftsBetween($employees, $from, $to) as $employeeId => $byDay) {
            $count = [];
            $byId = [];
            foreach ($byDay as $shift) {
                if ($shift !== null) {
                    $count[$shift->id] = ($count[$shift->id] ?? 0) + 1;
                    $byId[$shift->id] = $shift;
                }
            }
            arsort($count);
            $top = array_key_first($count);
            $dominant[$employeeId] = $top === null ? null : $byId[$top];
        }

        return $dominant;
    }

    /**
     * The ledger over everyone, a chunk at a time, one row at a time. Each row
     * carries the ledger's cells and totals, and the overtime limits payroll
     * applies to them.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant, with department, branch and position loaded
     * @return Generator<int, array{employee: Employee, cells: array<string, array<string, mixed>>, totals: array<string, mixed>, check_ok: bool, limits: array<string, mixed>}>
     */
    public function rows(Collection $employees, Carbon $from, Carbon $to, StatutoryRuleSet $rules, bool $withPay): Generator
    {
        foreach ($employees->chunk(self::CHUNK) as $chunk) {
            $built = $this->ledger->build($chunk->values(), $from, $to, $rules, $withPay);

            foreach ($built['employees'] as $row) {
                $row['limits'] = $this->payroll->overtimeLimits($row['employee'], $row['totals']['overtime_by_day'], $from, $to, $rules);

                yield $row;
            }
        }
    }

    public function rules(Tenant $tenant, Carbon $from): StatutoryRuleSet
    {
        return $this->statutory->for($tenant, $from);
    }

    /**
     * A row's totals as the flat figures the report source and the grid show:
     * days, hours (to one decimal), overtime hours by rate, and the flags.
     *
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $limits  PayrollEngine::overtimeLimits() for the row
     * @return array<string, int|float>
     */
    public function figures(array $totals, array $limits): array
    {
        $hours = fn (int $minutes): float => round($minutes / 60, 1);

        $figures = [
            'work_days' => $totals['scheduled_days'],
            'present_days' => $totals['present_days'],
            'late_days' => $totals['late_days'],
            'absent_days' => $totals['absent_days'],
            'leave_days' => $totals['leave_days'],
            'holiday_days' => $totals['holiday_days'],
            'worked_hours' => $hours($totals['worked_minutes']),
            'overtime_hours' => $hours(array_sum($totals['overtime'])),
            'rest_days' => $totals['rest_days'],
            'missing_days' => $totals['missing_days'],
            'early_days' => $totals['early_days'],
            'late_minutes' => $totals['late_minutes'],
            'early_minutes' => $totals['early_minutes'],
            'lateness_deduct_minutes' => $totals['lateness_deduct_minutes'],
            'scheduled_hours' => $hours($totals['scheduled_minutes']),
            'short_hours' => $hours($totals['short_minutes']),
        ];

        foreach (self::OVERTIME_FIELDS as $type => $field) {
            $figures[$field] = $hours($totals['overtime'][$type] ?? 0);
        }

        $figures['overtime_unapproved_hours'] = $hours($this->unapprovedMinutes($limits));
        $figures['overtime_limit_days'] = count(array_unique([...$limits['days_over_daily_limit'], ...$limits['days_over_legal_daily_limit']]));

        return $figures;
    }

    /**
     * Overtime worked past what was approved in advance, in an organisation
     * that asks for approval. Where it does not, overtime is not "unapproved":
     * payroll pays it either way (Art. 68), and so the figure is 0.
     *
     * @param  array<string, mixed>  $limits
     */
    public function unapprovedMinutes(array $limits): int
    {
        $minutes = 0;
        foreach ($limits['days_over_approved'] as $over) {
            $minutes += max(0, $over['worked_minutes'] - $over['approved_minutes']);
        }

        return $minutes;
    }

    /**
     * Calendar days employed, less unpaid leave and Art. 85 unpaid sick days:
     * the days payroll pays a full day for, by payroll's own figures. Absences
     * are not taken off (they are flagged on the run, never deducted), and
     * half-pay sick days are shown beside it.
     *
     * @param  array<string, mixed>  $totals  from a ledger built with `withPay`
     */
    public function payableDays(array $totals): float
    {
        return max(0.0, $totals['employed_days'] - $totals['unpaid_leave_days'] - $totals['sick_unpaid_days']);
    }

    /**
     * The overtime columns of a timesheet: one per distinct rate the
     * organisation pays, with the types that share it. Holiday and
     * holiday-at-night are both 2.5 times by the Proclamation, so they share
     * a column until an organisation pays them differently.
     *
     * @return list<array{rate: float, types: list<string>}>
     */
    public function rateColumns(int $tenantId, Carbon $asOf): array
    {
        $rates = $this->overtimeRates->ratesFor($tenantId, $asOf);
        $columns = [];

        foreach (self::OVERTIME_FIELDS as $type => $field) {
            $key = (string) $rates[$type];
            $columns[$key] ??= ['rate' => (float) $rates[$type], 'types' => []];
            $columns[$key]['types'][] = $type;
        }

        $columns = array_values($columns);
        usort($columns, fn (array $a, array $b): int => $a['rate'] <=> $b['rate']);

        return $columns;
    }

    /**
     * The status of the period, from what already exists: nothing here is
     * stored or signed off. Paid when payroll has released every employed day
     * (or part-paid when some), exceptions while a punch is still open or
     * pending, reconciled once the days are over and nothing is, open before.
     *
     * @param  array<string, mixed>  $totals  the grand totals over everyone listed
     * @param  list<string>  $listed  the public ids of everyone the totals cover: only what they have open counts
     * @return array{code: string, blocking: int, locked_days: int, employed_days: int}
     */
    public function status(Tenant $tenant, Carbon $from, Carbon $to, array $totals, array $listed): array
    {
        $blocking = 0;
        $listed = array_flip($listed);
        foreach ($this->reconciliation->forPeriod($tenant, $from, $to)['employees'] as $row) {
            if (! isset($listed[$row['employee_public_id']])) {
                continue;
            }
            foreach ($row['exceptions'] as $exception) {
                $blocking += $exception['state'] === AttendanceReconciliation::STATE_RESOLVED ? 0 : 1;
            }
        }

        $code = match (true) {
            ($totals['employed_days'] ?? 0) > 0 && ($totals['locked_days'] ?? 0) >= $totals['employed_days'] => 'paid',
            ($totals['locked_days'] ?? 0) > 0 => 'part_paid',
            $blocking > 0 => 'exceptions',
            $to->lt(Carbon::parse(TenantTime::today($tenant)->format('Y-m-d'))) => 'reconciled',
            default => 'open',
        };

        return [
            'code' => $code,
            'blocking' => $blocking,
            'locked_days' => $totals['locked_days'] ?? 0,
            'employed_days' => $totals['employed_days'] ?? 0,
        ];
    }

    /**
     * The whole monthly timesheet for one request: the page of people with
     * their day cells, the totals over everyone the filters match (not the
     * page), department subtotals, the rate columns, the rules applied and the
     * status. One ledger pass over everyone, chunked; only the page's rows are
     * kept, the rest only add to the totals.
     *
     * @param  array<string, mixed>  $filters  from, to, month, department, branch, shift, employment_status
     * @return array<string, mixed>
     */
    public function grid(Tenant $tenant, User $viewer, array $filters, int $page, int $perPage, bool $withPay): array
    {
        $today = Carbon::parse(TenantTime::today($tenant)->format('Y-m-d'));
        $span = $this->span($filters, $today, self::MAX_DAYS);

        if ($span === null) {
            return ['period' => null, 'days' => [], 'data' => [], 'total' => 0];
        }
        [$from, $to] = $span;

        $employees = $this->employees($tenant->id, $filters, $viewer, $from, $to)
            ->with(['department:id,public_id,name,name_am', 'position:id,title,title_am'])
            ->orderBy('name')
            ->get();

        // A shift filter needs everyone's shift. Without one only the people
        // shown need it, so the work grows with the page and not the organisation.
        $shiftByEmployee = [];
        $wanted = $filters['shift'] ?? null;
        if (is_string($wanted) && $wanted !== '') {
            $shiftByEmployee = $this->dominantShifts($employees, $from, $to);
            $employees = $employees->filter(fn (Employee $e): bool => (isset($shiftByEmployee[$e->id]) ? $shiftByEmployee[$e->id]->public_id : null) === $wanted)->values();
        }

        $rules = $this->rules($tenant, $from);
        $rateColumns = $this->rateColumns($tenant->id, $from);
        $matched = $employees->count();

        // Within the limit the whole set is read, for the totals. Past it only
        // the page is, and the totals are left out rather than shown for a part.
        $complete = $matched <= self::MAX_PEOPLE;
        $first = $complete ? ($page - 1) * $perPage : 0;
        $last = $complete ? $first + $perPage : PHP_INT_MAX;
        $scope = $complete ? $employees : $employees->slice(($page - 1) * $perPage, $perPage)->values();
        $shiftByEmployee += $this->dominantShifts($scope->reject(fn (Employee $e): bool => array_key_exists($e->id, $shiftByEmployee))->values(), $from, $to);

        $kept = [];
        $listed = [];
        $grand = [];
        $byDepartment = [];
        $usedShifts = [];
        $index = 0;

        foreach ($this->rows($scope, $from, $to, $rules, withPay: $withPay) as $row) {
            $employee = $row['employee'];
            $summary = $this->summary($row, $rateColumns, $withPay);

            $this->add($grand, $summary);
            $listed[] = $employee->public_id;
            $departmentKey = $employee->department === null ? '' : $employee->department->public_id;
            $byDepartment[$departmentKey]['department'] ??= $employee->department;
            $byDepartment[$departmentKey]['employees'] = ($byDepartment[$departmentKey]['employees'] ?? 0) + 1;
            $byDepartment[$departmentKey]['totals'] ??= [];
            $this->add($byDepartment[$departmentKey]['totals'], $summary);

            $shift = $shiftByEmployee[$employee->id] ?? null;
            if ($shift !== null) {
                $usedShifts[$shift->id] = $shift;
            }

            if ($index >= $first && $index < $last) {
                $kept[] = [
                    'employee' => $employee,
                    'shift' => $shift,
                    'cells' => $row['cells'],
                    'totals' => $summary,
                    'check_ok' => $row['check_ok'],
                ];
            }
            $index++;
        }

        $days = [];
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $days[] = ['date' => $day->format('Y-m-d'), 'iso_weekday' => $day->dayOfWeekIso];
        }

        $grand = $this->rounded($grand);

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'days' => $days,
            'data' => $kept,
            'total' => $matched,
            'complete' => $complete,
            'max_people' => self::MAX_PEOPLE,
            'totals' => $complete ? $grand : null,
            'department_subtotals' => $complete ? array_values(array_map(fn (array $group): array => [
                'department' => $group['department'],
                'employees' => $group['employees'],
                'totals' => $this->rounded($group['totals']),
            ], $byDepartment)) : [],
            'rate_columns' => $rateColumns,
            'rules_applied' => $this->rulesApplied($tenant, $from, $rules, array_values($usedShifts)),
            'status' => $complete ? $this->status($tenant, $from, $to, $grand, $listed) : null,
            'with_pay' => $withPay,
        ];
    }

    /**
     * One row's figures for the grid: the flat figures, the pay-moving days,
     * and the overtime hours under each rate column.
     *
     * @param  array{employee: Employee, cells: array<string, array<string, mixed>>, totals: array<string, mixed>, check_ok: bool, limits: array<string, mixed>}  $row
     * @param  list<array{rate: float, types: list<string>}>  $rateColumns
     * @param  bool  $withPay  the viewer may see the pay-moving figures (payroll.viewAll)
     * @return array<string, mixed>
     */
    private function summary(array $row, array $rateColumns, bool $withPay): array
    {
        $totals = $row['totals'];

        return [
            ...$this->figures($totals, $row['limits']),
            'pending_days' => $totals['pending_days'],
            'locked_days' => $totals['locked_days'],
            'employed_days' => $totals['employed_days'],
            ...($withPay ? [
                'unpaid_leave_days' => $totals['unpaid_leave_days'],
                'sick_half_days' => $totals['sick_half_days'],
                'sick_unpaid_days' => $totals['sick_unpaid_days'],
                'payable_days' => $this->payableDays($totals),
            ] : []),
            'overtime_limit_weeks' => count($row['limits']['weeks_over_legal_weekly_limit']),
            'leave_by_code' => $totals['leave_by_code'],
            'overtime_by_rate' => array_map(
                fn (array $column): float => round(array_sum(array_map(fn (string $type): int => $totals['overtime'][$type] ?? 0, $column['types'])) / 60, 1),
                $rateColumns,
            ),
        ];
    }

    /**
     * Adds one row's figures to a running total: numbers add, and the leave
     * and rate lists add by key.
     *
     * @param  array<string, mixed>  $total
     * @param  array<string, mixed>  $summary
     */
    private function add(array &$total, array $summary): void
    {
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $inner => $amount) {
                    $total[$key][$inner] = ($total[$key][$inner] ?? 0) + $amount;
                }

                continue;
            }

            $total[$key] = ($total[$key] ?? 0) + $value;
        }
    }

    /**
     * Totals with the float noise of adding tenths taken off: a column of
     * 7.1 and 0.2 must read 7.3 in its footer, not 7.300000000000001.
     *
     * @param  array<string, mixed>  $total
     * @return array<string, mixed>
     */
    private function rounded(array $total): array
    {
        foreach ($total as $key => $value) {
            if (is_array($value)) {
                $total[$key] = array_map(fn (int|float $amount): int|float => is_float($amount) ? round($amount, 1) : $amount, $value);
            } elseif (is_float($value)) {
                $total[$key] = round($value, 1);
            }
        }

        return $total;
    }

    /**
     * What this timesheet applied, as data the screen and the print put into
     * words: every figure is one the engine reads, never a setting of the
     * report's own.
     *
     * @param  list<Shift>  $shifts  the shifts the people listed worked
     * @return array<string, mixed>
     */
    public function rulesApplied(Tenant $tenant, Carbon $from, StatutoryRuleSet $rules, array $shifts): array
    {
        $attendance = AttendanceSetting::query()->where('tenant_id', $tenant->id)->first();

        return [
            'shifts' => array_map(fn (Shift $shift): array => [
                'public_id' => $shift->public_id,
                'name' => $shift->name,
                'name_am' => $shift->name_am,
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'grace_minutes' => (int) $shift->grace_minutes,
                'early_departure_minutes' => (int) $shift->early_departure_minutes,
                'break_minutes' => (int) $shift->break_minutes,
            ], $shifts),
            'punch_pairing' => 'first_in_last_out',
            'merge_window_minutes' => AttendanceRules::MERGE_WINDOW_MINUTES,
            'night_shift_dated_to' => 'start',
            'auto_checkout' => [
                'enabled' => $attendance !== null && $attendance->auto_checkout_enabled,
                'after_minutes' => $attendance === null ? 0 : (int) $attendance->auto_checkout_after_minutes,
            ],
            'overtime' => [
                'night_window' => $rules->list('overtime.night_window'),
                'legal_daily_limit_minutes' => $rules->int('overtime.legal_daily_minutes'),
                'legal_weekly_limit_minutes' => $rules->int('overtime.legal_weekly_minutes'),
                'requires_approval' => $attendance !== null && $attendance->overtime_requires_approval,
                'paid_when_unapproved' => true,
            ],
            'reconciliation_mode' => AttendanceReconciliation::mode($tenant->id),
            'absences_are' => 'flagged_not_deducted',
        ];
    }
}
