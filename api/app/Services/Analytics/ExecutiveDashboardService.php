<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ExpectedDay;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Attendance\ExpectedDays;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backs the persona-scoped executive dashboards (CEO / HR Director / Finance /
 * Operations / Regional Manager — see ExecutiveDashboardController). Every
 * method accepts an optional `$branchId`: null means "whole tenant" (the view
 * a `dashboard.executive` holder gets), a value scopes every query to that
 * branch (the view a `dashboard.regional` holder is forced into). None of the
 * underlying models carry their own `branch_id`, so branch scoping always
 * routes through a join to `employees.branch_id`.
 */
final class ExecutiveDashboardService
{
    public function __construct(
        private readonly ExpectedDays $expectedDays,
    ) {}

    public function overview(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): array
    {
        return [
            'headcount' => $this->headcount($tenantId, $branchId),
            'attendance_rate' => $this->attendanceRate($tenantId, $from, $to, $branchId),
            'payroll_summary' => $this->payrollSummary($tenantId, $from, $to, $branchId),
            'turnover' => $this->turnoverRate($tenantId, $from, $to, $branchId),
            'workforce_growth' => $this->workforceGrowth($tenantId, $branchId),
            'leave_utilization' => $this->leaveUtilization($tenantId, $branchId),
        ];
    }

    public function attendanceDeepDive(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): array
    {
        return [
            'daily_trend' => $this->dailyAttendanceTrend($tenantId, $from, $to, $branchId),
            'by_department' => $this->attendanceByDepartment($tenantId, $from, $to, $branchId),
            'by_source' => $this->attendanceBySource($tenantId, $from, $to, $branchId),
            'top_late' => $this->topLateEmployees($tenantId, $from, $to, $branchId),
        ];
    }

    public function payrollDeepDive(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): array
    {
        return [
            'monthly_trend' => $this->monthlyPayrollTrend($tenantId, $branchId),
            'overtime_trend' => $this->overtimeTrend($tenantId, $branchId),
            'by_department' => $this->payrollByDepartment($tenantId, $from, $to, $branchId),
            'by_cost_center' => $this->payrollByCostCenter($tenantId, $from, $to, $branchId),
            'totals' => $this->payrollTotals($tenantId, $from, $to, $branchId),
        ];
    }

    public function workforceDeepDive(int $tenantId, ?int $branchId = null): array
    {
        return [
            'headcount_trend' => $this->headcountTrend($tenantId, $branchId),
            'by_department' => $this->headcountByDepartment($tenantId, $branchId),
            'by_gender' => $this->genderDistribution($tenantId, $branchId),
            'by_tenure' => $this->tenureDistribution($tenantId, $branchId),
        ];
    }

    /**
     * Real, defensible compliance signals built from data the platform already
     * tracks — deliberately not a fabricated "compliance score". Each figure is
     * a factual count with a clear definition:
     *  - documents expiring within 30 days (employee.documents already tracks expiry)
     *  - employees whose probation period has lapsed with no confirm/terminate
     *    decision recorded (an operational risk, not a legal judgment)
     *  - employees who have used zero leave days this year (an observation,
     *    not an assertion about statutory minimums this platform can't verify)
     */
    public function complianceSnapshot(int $tenantId, ?int $branchId = null): array
    {
        return [
            'expiring_documents' => $this->expiringDocuments($tenantId, $branchId),
            'probation_overdue' => $this->probationOverdue($tenantId, $branchId),
            'unused_leave' => $this->unusedLeave($tenantId, $branchId),
        ];
    }

    /**
     * A simple, transparent linear-trend projection — not a black-box model.
     * Matches the platform's established "deterministic, explainable, no paid
     * API dependency" policy for anything labelled AI/forecasting.
     */
    public function forecast(int $tenantId, ?int $branchId = null): array
    {
        $headcount = $this->headcountTrend($tenantId, $branchId);
        $payroll = $this->monthlyPayrollTrend($tenantId, $branchId);

        $payrollHistory = [];
        foreach ($payroll as $run) {
            $payrollHistory[] = ['month' => (string) $run['period'], 'value' => (int) $run['gross_cents']];
        }

        return [
            'headcount' => [
                'history' => $headcount,
                'projected' => TrendForecaster::project(
                    array_column($headcount, 'count'),
                    array_column($headcount, 'month'),
                    3,
                ),
            ],
            'payroll_gross' => [
                'history' => $payrollHistory,
                'projected' => TrendForecaster::project(
                    array_column($payroll, 'gross_cents'),
                    array_column($payroll, 'period'),
                    3,
                ),
            ],
        ];
    }

    // ── Employee-query scoping ──────────────────────────────────────────

    /** @return Builder<Employee> */
    private function scopedEmployees(int $tenantId, ?int $branchId = null): Builder
    {
        $query = Employee::withoutGlobalScope('tenant')->where('tenant_id', $tenantId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private const ACTIVE_STATUSES = [
        EmployeeStatus::HIRED,
        EmployeeStatus::PROBATION,
        EmployeeStatus::CONFIRMED,
    ];

    private const LEAVING_STATUSES = [
        EmployeeStatus::RESIGNED,
        EmployeeStatus::TERMINATED,
        EmployeeStatus::RETIRED,
    ];

    /**
     * Employees on the books on `$day`, by dates rather than today's status:
     * hired on or before it, and not yet left (no termination date, or one on
     * or after it). Suspended staff are still employed. A leaver with no
     * recorded termination date is left out, since when they went is unknown.
     *
     * The one definition behind headcount, the headcount trend and turnover.
     * Until 2026-10-20 each counted differently: `total` included everyone ever
     * recorded, the trend rebuilt past months from today's status, and
     * turnover dated exits by `updated_at` (blueprint assessment RP3, RP24,
     * RP26).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function employedOn(Builder $query, Carbon $day): Builder
    {
        $date = $day->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $date))
            ->where(fn ($q) => $q->whereDate('termination_date', '>=', $date)
                ->orWhere(fn ($q2) => $q2->whereNull('termination_date')->whereNotIn('status', self::LEAVING_STATUSES)));
    }

    private function headcount(int $tenantId, ?int $branchId): array
    {
        $active = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        $total = $this->employedOn($this->scopedEmployees($tenantId, $branchId), Carbon::today())->count();

        return [
            'total' => $total,
            'active' => $active,
            'by_department' => $this->headcountByDepartment($tenantId, $branchId),
        ];
    }

    /**
     * Check-ins against the days people were expected at work, by the rule the
     * attendance screen uses (`ExpectedDays`): leavers, approved leave,
     * holidays and rest days are not expected. Until 2026-10-29 the base was
     * every active employee on every Monday to Friday of the period, future
     * days included, so a Sunday read 0% and the period rate mid-month was
     * about half the real one. "Today" is the organisation's date, not UTC's.
     *
     * Null when nobody was expected: no one to attend is not 0% attendance.
     */
    private function attendanceRate(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $employees = $this->scopedEmployees($tenantId, $branchId)
            ->get(['id', 'tenant_id', 'department_id', 'branch_id', 'status', 'hire_date', 'termination_date']);

        $today = Carbon::parse(TenantTime::today(Tenant::query()->find($tenantId))->format('Y-m-d'));
        $last = $to->lessThan($today) ? $to->copy()->startOfDay() : $today->copy();

        return [
            'today' => $this->attendedShare($tenantId, $employees, $today, $today),
            'period' => $this->attendedShare($tenantId, $employees, $from->copy()->startOfDay(), $last),
        ];
    }

    /**
     * Expected employee-days in [$first, $last] that have a check-in, as a
     * percentage. A check-in on a rest day counts on neither side.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant
     */
    private function attendedShare(int $tenantId, Collection $employees, Carbon $first, Carbon $last): ?float
    {
        if ($employees->isEmpty() || $first->greaterThan($last)) {
            return null;
        }

        $attended = [];
        $records = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('date', '>=', $first->toDateString())
            ->whereDate('date', '<=', $last->toDateString())
            ->whereNotNull('check_in')
            ->where('status', '!=', AttendanceStatus::VOIDED)
            // Plain rows: a year is tens of thousands of records, and a model
            // per row with a date cast per read cost more than the query.
            ->toBase()
            ->get(['employee_id', 'date']);

        foreach ($records as $record) {
            $attended[substr((string) $record->date, 0, 10)][(int) $record->employee_id] = true;
        }

        $expected = 0;
        $present = 0;

        foreach ($this->expectedDays->between($employees, $first, $last) as $day => $kinds) {
            foreach ($kinds as $id => $kind) {
                if ($kind !== ExpectedDay::WORKDAY) {
                    continue;
                }

                $expected++;
                if (isset($attended[$day][$id])) {
                    $present++;
                }
            }
        }

        return $expected > 0 ? round(($present / $expected) * 100, 1) : null;
    }

    /**
     * Joins `employees` onto an attendance_records query and filters by branch, when given.
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    private function joinBranch(Builder $query, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $query->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->where('employees.branch_id', $branchId);
    }

    /**
     * The runs a payroll figure counts: calculated, approved or paid. A run
     * still processing has no totals yet, and a voided one was replaced; until
     * 2026-10-29 the period totals, the overview card and the department and
     * cost-centre splits counted both, so a voided and re-run month counted
     * twice. The trends already filtered this way.
     */
    private const COUNTED_RUN_STATUSES = ['completed', ...PayrollRun::RELEASED_STATUSES];

    /**
     * The counted runs that belong to [$from, $to]: those whose period's
     * middle day falls inside it, the rule `localizedPeriodLabel()` names a
     * run's month by. Until 2026-10-30 a run had to fit wholly inside the
     * range, so an Ethiopian month (Tikimt is 11 October to 9 November) held
     * no Gregorian-month run at all, and an organisation paying on Ethiopian
     * months had none in any Gregorian one. A run's middle lies in exactly
     * one month of either calendar.
     *
     * @return Builder<PayrollRun>
     */
    private function countedRuns(int $tenantId, Carbon $from, Carbon $to): Builder
    {
        $first = $from->toDateString();
        $last = $to->toDateString();

        $ids = PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::COUNTED_RUN_STATUSES)
            ->whereDate('period_start', '<=', $last)
            ->whereDate('period_end', '>=', $first)
            ->get(['id', 'period_start', 'period_end'])
            ->filter(function (PayrollRun $run) use ($first, $last): bool {
                if ($run->period_start === null || $run->period_end === null) {
                    return false;
                }

                $middle = $run->period_start->copy()
                    ->addDays(intdiv((int) $run->period_start->diffInDays($run->period_end), 2))
                    ->toDateString();

                return $middle >= $first && $middle <= $last;
            })
            ->modelKeys();

        return PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids);
    }

    private function payrollSummary(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        // NoOverflow: the 31st of a month minus one month is otherwise the
        // 1st of the same month.
        $previousFrom = $from->copy()->subMonthNoOverflow();
        $previousTo = $to->copy()->subMonthNoOverflow();

        if ($branchId === null) {
            $current = $this->countedRuns($tenantId, $from, $to)->get();
            // An off-cycle run pays some of the same people again, so the
            // largest run is the count, not the sum.
            $employeeCount = (int) ($current->max('employee_count') ?? 0);
            $previous = $this->countedRuns($tenantId, $previousFrom, $previousTo)->get();

            return [
                'gross_cents' => (int) $current->sum('gross_total_cents'),
                'net_cents' => (int) $current->sum('net_total_cents'),
                'tax_cents' => (int) $current->sum('tax_total_cents'),
                'previous_gross_cents' => (int) $previous->sum('gross_total_cents'),
                'employee_count' => $employeeCount,
            ];
        }

        // Branch-scoped: the run's precomputed totals are tenant-wide, so sum
        // directly from entries joined to the branch's employees instead.
        $current = $this->branchPayrollTotals($tenantId, $branchId, $from, $to);
        $previous = $this->branchPayrollTotals($tenantId, $branchId, $previousFrom, $previousTo);

        return [
            'gross_cents' => $current['gross_cents'],
            'net_cents' => $current['net_cents'],
            'tax_cents' => $current['tax_cents'],
            'previous_gross_cents' => $previous['gross_cents'],
            'employee_count' => $current['employee_count'],
        ];
    }

    /** @return array{gross_cents: int, net_cents: int, tax_cents: int, employee_count: int} */
    private function branchPayrollTotals(int $tenantId, int $branchId, Carbon $from, Carbon $to): array
    {
        $row = PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_entries.tenant_id', $tenantId)
            ->whereIn('payroll_entries.payroll_run_id', $this->countedRuns($tenantId, $from, $to)->pluck('id'))
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->where('employees.branch_id', $branchId)
            ->selectRaw('
                coalesce(sum(payroll_entries.gross_cents), 0) as gross_cents,
                coalesce(sum(payroll_entries.net_cents), 0) as net_cents,
                coalesce(sum(payroll_entries.income_tax_cents), 0) as tax_cents,
                count(distinct payroll_entries.employee_id) as employee_count
            ')
            ->first();

        return [
            'gross_cents' => (int) ($row->gross_cents ?? 0),
            'net_cents' => (int) ($row->net_cents ?? 0),
            'tax_cents' => (int) ($row->tax_cents ?? 0),
            'employee_count' => (int) ($row->employee_count ?? 0),
        ];
    }

    private function turnoverRate(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        // Exits: people whose employment ended in the period, by the date it
        // ended. Rate: exits over the average of the headcount at the start
        // and at the end of the period.
        $exits = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::LEAVING_STATUSES)
            ->whereDate('termination_date', '>=', $from->toDateString())
            ->whereDate('termination_date', '<=', $to->toDateString())
            ->count();

        $atStart = $this->employedOn($this->scopedEmployees($tenantId, $branchId), $from)->count();
        $atEnd = $this->employedOn($this->scopedEmployees($tenantId, $branchId), $to)->count();
        $avgHeadcount = ($atStart + $atEnd) / 2;

        $rate = $avgHeadcount > 0 ? round(($exits / $avgHeadcount) * 100, 1) : 0;

        return [
            'exits' => $exits,
            'rate' => $rate,
        ];
    }

    private function workforceGrowth(int $tenantId, ?int $branchId): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            // From the 1st: on the 31st, now() minus one month is the 1st of
            // this month, and the chart showed it twice and skipped the last.
            $month = Carbon::now()->startOfMonth()->subMonths($i);
            $hires = $this->scopedEmployees($tenantId, $branchId)
                ->whereYear('hire_date', $month->year)
                ->whereMonth('hire_date', $month->month)
                ->count();

            $months[] = [
                'month' => $month->format('Y-m'),
                'hires' => $hires,
            ];
        }

        return $months;
    }

    private function leaveUtilization(int $tenantId, ?int $branchId): array
    {
        $year = Carbon::now()->year;

        $balances = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->whereIn('leave_type_id', self::timeOffTypes($tenantId))
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($eq) => $eq->where('branch_id', $branchId),
            ))
            ->get();

        // The columns are `decimal:1` casts, so each value is a numeric string
        // and their float sum drifts (1.1 + 2.2 = 3.3000000000000003); rounding
        // back to the column's one decimal is what the screen prints.
        $totalEntitled = round((float) $balances->sum('entitled_days'), 1);
        $totalUsed = round((float) $balances->sum('used_days'), 1);
        $rate = $totalEntitled > 0 ? round(($totalUsed / $totalEntitled) * 100, 1) : 0;

        return [
            'entitled_days' => $totalEntitled,
            'used_days' => $totalUsed,
            'utilization_rate' => $rate,
        ];
    }

    private function dailyAttendanceTrend(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
            // A voided record is a merged duplicate.
            ->where('attendance_records.status', '!=', AttendanceStatus::VOIDED);
        $this->joinBranch($query, $branchId);

        return $query
            ->selectRaw('attendance_records.date, count(distinct attendance_records.employee_id) as present')
            ->groupBy('attendance_records.date')
            ->orderBy('attendance_records.date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date->toDateString(),
                'present' => (int) $r->getAttribute('present'),
            ])
            ->all();
    }

    private function attendanceByDepartment(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
            ->where('attendance_records.status', '!=', AttendanceStatus::VOIDED)
            ->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id');

        if ($branchId !== null) {
            $query->where('employees.branch_id', $branchId);
        }

        return $query
            ->selectRaw('departments.name as department, count(distinct attendance_records.employee_id) as present')
            ->groupBy('departments.name')
            ->get()
            ->map(fn ($r) => [
                'department' => (string) $r->getAttribute('department'),
                'present' => (int) $r->getAttribute('present'),
            ])
            ->all();
    }

    private function attendanceBySource(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
            // A voided record is a merged duplicate.
            ->where('attendance_records.status', '!=', AttendanceStatus::VOIDED);
        $this->joinBranch($query, $branchId);

        return $query
            ->selectRaw('attendance_records.source, count(*) as count')
            ->groupBy('attendance_records.source')
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source->value,
                'count' => (int) $r->getAttribute('count'),
            ])
            ->all();
    }

    private function topLateEmployees(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
            ->where('attendance_records.status', 'late');
        $this->joinBranch($query, $branchId);

        return $query
            ->selectRaw('attendance_records.employee_id, count(*) as late_count')
            ->groupBy('attendance_records.employee_id')
            ->orderByDesc('late_count')
            ->limit(10)
            ->with('employee:id,name,public_id')
            ->get()
            ->map(fn ($r) => [
                'employee_name' => $r->employee?->name,
                'employee_public_id' => $r->employee?->public_id,
                'late_count' => (int) $r->getAttribute('late_count'),
            ])
            ->all();
    }

    /**
     * The latest twelve runs, oldest first. Both branches select newest-first
     * and then reverse: ordering ascending before the limit kept the *first*
     * twelve runs a tenant ever made, so the chart stopped moving after a year.
     */
    private function monthlyPayrollTrend(int $tenantId, ?int $branchId): array
    {
        if ($branchId === null) {
            return PayrollRun::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', self::COUNTED_RUN_STATUSES)
                ->orderByDesc('period_start')
                ->limit(12)
                ->get()
                ->reverse()
                ->values()
                ->map(fn ($r) => [
                    'period' => $r->period_label,
                    'gross_cents' => $r->gross_total_cents,
                    'net_cents' => $r->net_total_cents,
                    'tax_cents' => $r->tax_total_cents,
                ])
                ->all();
        }

        return PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_entries.tenant_id', $tenantId)
            ->join('payroll_runs', 'payroll_entries.payroll_run_id', '=', 'payroll_runs.id')
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->where('employees.branch_id', $branchId)
            ->whereIn('payroll_runs.status', self::COUNTED_RUN_STATUSES)
            ->selectRaw('
                payroll_runs.id as run_id,
                payroll_runs.period_label as period,
                payroll_runs.period_start as period_start,
                sum(payroll_entries.gross_cents) as gross_cents,
                sum(payroll_entries.net_cents) as net_cents,
                sum(payroll_entries.income_tax_cents) as tax_cents
            ')
            ->groupBy('payroll_runs.id', 'payroll_runs.period_label', 'payroll_runs.period_start')
            ->orderByDesc('payroll_runs.period_start')
            ->limit(12)
            ->get()
            ->reverse()
            ->values()
            // These are selectRaw aliases, not real PayrollEntry columns —
            // getAttribute() reads them without asserting a static property
            // that doesn't exist on the model's real schema.
            ->map(fn ($r) => [
                'period' => (string) $r->getAttribute('period'),
                'gross_cents' => (int) $r->getAttribute('gross_cents'),
                'net_cents' => (int) $r->getAttribute('net_cents'),
                'tax_cents' => (int) $r->getAttribute('tax_cents'),
            ])
            ->all();
    }

    /**
     * Overtime has no dedicated stored column — `PayrollEngine` folds it into
     * `gross_cents` and records the breakdown only inside each entry's
     * `calculation_log` (the same audit trail a payslip is built from, under
     * a `steps` array with `step === 'overtime'`). Reading that here instead
     * of adding an `overtime_cents` column means this never touches the
     * payroll calculation pipeline, and the trend is correct for every
     * period already run historically, not just future ones.
     */
    private function overtimeTrend(int $tenantId, ?int $branchId): array
    {
        $query = PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_entries.tenant_id', $tenantId)
            ->join('payroll_runs', 'payroll_entries.payroll_run_id', '=', 'payroll_runs.id')
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->whereIn('payroll_runs.status', self::COUNTED_RUN_STATUSES);

        if ($branchId !== null) {
            $query->where('employees.branch_id', $branchId);
        }

        $rows = $query
            ->select(
                'payroll_runs.id as run_id',
                'payroll_runs.period_label as period',
                'payroll_runs.period_start as period_start',
                'payroll_entries.calculation_log',
            )
            ->get();

        return $rows
            ->groupBy(fn ($r) => (int) $r->getAttribute('run_id'))
            ->map(function ($entries) {
                $overtimeCents = 0;
                $overtimeMinutes = 0;

                foreach ($entries as $entry) {
                    $steps = $this->calculationLogSteps($entry->getAttribute('calculation_log'));
                    $overtimeStep = collect($steps)->firstWhere('step', 'overtime');
                    $overtimeCents += (int) ($overtimeStep['overtime_amount_cents'] ?? 0);
                    $overtimeMinutes += (int) ($overtimeStep['overtime_minutes'] ?? 0);
                }

                $first = $entries->first();

                return [
                    'period' => $first->getAttribute('period'),
                    'period_start' => $first->getAttribute('period_start'),
                    'overtime_cents' => $overtimeCents,
                    'overtime_minutes' => $overtimeMinutes,
                ];
            })
            ->sortBy('period_start')
            ->values()
            ->slice(-12)
            ->map(fn ($row) => [
                'period' => (string) $row['period'],
                'overtime_cents' => $row['overtime_cents'],
                'overtime_minutes' => $row['overtime_minutes'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function calculationLogSteps(mixed $log): array
    {
        if (! is_array($log) || ! isset($log['steps']) || ! is_array($log['steps'])) {
            return [];
        }

        return $log['steps'];
    }

    private function payrollByDepartment(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $runs = $this->countedRuns($tenantId, $from, $to)->pluck('id');

        if ($runs->isEmpty()) {
            return [];
        }

        $query = PayrollEntry::withoutGlobalScope('tenant')
            ->whereIn('payroll_entries.payroll_run_id', $runs)
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id');

        if ($branchId !== null) {
            $query->where('employees.branch_id', $branchId);
        }

        return $query
            ->selectRaw('departments.name as department, sum(payroll_entries.gross_cents) as total_gross, count(*) as employee_count')
            ->groupBy('departments.name')
            ->get()
            ->map(fn ($r) => [
                'department' => (string) $r->getAttribute('department'),
                'total_gross_cents' => (int) $r->getAttribute('total_gross'),
                'employee_count' => (int) $r->getAttribute('employee_count'),
            ])
            ->all();
    }

    private function payrollByCostCenter(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $runs = $this->countedRuns($tenantId, $from, $to)->pluck('id');

        if ($runs->isEmpty()) {
            return [];
        }

        $query = PayrollEntry::withoutGlobalScope('tenant')
            ->whereIn('payroll_entries.payroll_run_id', $runs)
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            // Left, not inner: `cost_center_id` is optional on Employee, and an
            // inner join here would silently drop (and undercount against
            // `payrollTotals()`) every employee a tenant hasn't assigned a cost
            // center to yet, rather than surfacing them as unassigned.
            ->leftJoin('cost_centers', 'employees.cost_center_id', '=', 'cost_centers.id');

        if ($branchId !== null) {
            $query->where('employees.branch_id', $branchId);
        }

        return $query
            ->selectRaw("COALESCE(cost_centers.name, 'Unassigned') as cost_center, sum(payroll_entries.gross_cents) as total_gross, count(*) as employee_count")
            ->groupBy(DB::raw("COALESCE(cost_centers.name, 'Unassigned')"))
            ->get()
            ->map(fn ($r) => [
                'cost_center' => (string) $r->getAttribute('cost_center'),
                'total_gross_cents' => (int) $r->getAttribute('total_gross'),
                'employee_count' => (int) $r->getAttribute('employee_count'),
            ])
            ->all();
    }

    private function payrollTotals(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        if ($branchId === null) {
            $runs = $this->countedRuns($tenantId, $from, $to)->get();

            return [
                'total_gross_cents' => (int) $runs->sum('gross_total_cents'),
                'total_net_cents' => (int) $runs->sum('net_total_cents'),
                'total_tax_cents' => (int) $runs->sum('tax_total_cents'),
                'run_count' => $runs->count(),
            ];
        }

        $totals = $this->branchPayrollTotals($tenantId, $branchId, $from, $to);

        $runCount = $this->countedRuns($tenantId, $from, $to)
            ->whereHas('entries', function ($q) use ($branchId) {
                $q->whereHas('employee', fn ($eq) => $eq->where('branch_id', $branchId));
            })
            ->count();

        return [
            'total_gross_cents' => $totals['gross_cents'],
            'total_net_cents' => $totals['net_cents'],
            'total_tax_cents' => $totals['tax_cents'],
            'run_count' => $runCount,
        ];
    }

    private function headcountTrend(int $tenantId, ?int $branchId): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->startOfMonth()->subMonths($i);
            $count = $this->employedOn($this->scopedEmployees($tenantId, $branchId), $month->copy()->endOfMonth())->count();

            $months[] = [
                'month' => $month->format('Y-m'),
                'count' => $count,
            ];
        }

        return $months;
    }

    private function headcountByDepartment(int $tenantId, ?int $branchId): array
    {
        return $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('department_id, count(*) as count')
            ->groupBy('department_id')
            ->with('department:id,public_id,name')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department?->name ?? 'Unassigned',
                'department_public_id' => $r->department?->public_id,
                'count' => (int) $r->getAttribute('count'),
            ])
            ->all();
    }

    private function genderDistribution(int $tenantId, ?int $branchId): array
    {
        return $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('gender, count(*) as count')
            ->groupBy('gender')
            ->get()
            ->map(fn ($r) => [
                'gender' => $r->gender,
                'count' => (int) $r->getAttribute('count'),
            ])
            ->all();
    }

    private function tenureDistribution(int $tenantId, ?int $branchId): array
    {
        $employees = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('hire_date')
            ->get();

        $buckets = ['<1yr' => 0, '1-3yr' => 0, '3-5yr' => 0, '5-10yr' => 0, '10+yr' => 0];

        foreach ($employees as $e) {
            $years = Carbon::parse($e->hire_date)->diffInYears(Carbon::now());
            if ($years < 1) {
                $buckets['<1yr']++;
            } elseif ($years < 3) {
                $buckets['1-3yr']++;
            } elseif ($years < 5) {
                $buckets['3-5yr']++;
            } elseif ($years < 10) {
                $buckets['5-10yr']++;
            } else {
                $buckets['10+yr']++;
            }
        }

        $rows = [];
        foreach ($buckets as $bucket => $count) {
            $rows[] = ['bucket' => $bucket, 'count' => (int) $count];
        }

        return $rows;
    }

    // ── Compliance ───────────────────────────────────────────────────────

    private function expiringDocuments(int $tenantId, ?int $branchId): array
    {
        // A relation constraint (rather than a join + explicit select) keeps
        // the query's own column shape untouched, so every accessor below
        // still resolves against EmployeeDocument's real schema.
        $documents = EmployeeDocument::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($eq) => $eq->where('branch_id', $branchId),
            ))
            ->with('employee:id,public_id,name')
            ->orderBy('expiry_date')
            ->limit(200)
            ->get();

        return [
            'count' => $documents->count(),
            // getAttribute() here, not ->employee/->expiry_date: the eager
            // load's column-limited select degrades Larastan's relation
            // inference to a generic Model for this collection.
            'items' => $documents->take(5)->map(fn ($d) => [
                'employee_name' => $d->getAttribute('employee')?->getAttribute('name'),
                'employee_public_id' => $d->getAttribute('employee')?->getAttribute('public_id'),
                'document_type' => $d->type,
                'expiry_date' => $d->getAttribute('expiry_date')?->toDateString(),
            ])->values(),
        ];
    }

    private function probationOverdue(int $tenantId, ?int $branchId): array
    {
        $overdue = $this->scopedEmployees($tenantId, $branchId)
            ->where('status', EmployeeStatus::PROBATION)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<', now())
            ->with('department:id,name')
            ->orderBy('probation_end_date')
            ->limit(200)
            ->get();

        return [
            'count' => $overdue->count(),
            'items' => $overdue->take(5)->map(fn ($e) => [
                'employee_name' => $e->name,
                'employee_public_id' => $e->public_id,
                'department' => $e->department?->name,
                'probation_end_date' => $e->getAttribute('probation_end_date')?->toDateString(),
            ])->values(),
        ];
    }

    private function unusedLeave(int $tenantId, ?int $branchId): array
    {
        $year = Carbon::now()->year;

        // Only meaningful once most of the year has elapsed — flagging it in
        // January would just be "nobody has taken leave yet".
        if (Carbon::now()->dayOfYear < 270) {
            return ['count' => 0, 'applicable' => false];
        }

        $count = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->where('used_days', 0)
            ->where('entitled_days', '>', 0)
            ->whereIn('leave_type_id', self::timeOffTypes($tenantId))
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($eq) => $eq->where('branch_id', $branchId),
            ))
            ->count();

        return [
            'count' => $count,
            'applicable' => true,
        ];
    }

    /**
     * The organisation's leave types that are time off. Trips and excused
     * absences (`duty`, `excused`) are not leave taken, so they stay out of
     * leave utilisation. A builder subquery stating `tenant_id`, because these
     * balance queries run without the tenant scope.
     */
    private static function timeOffTypes(int $tenantId): \Closure
    {
        return fn ($q) => $q->select('id')
            ->from('leave_types')
            ->where('tenant_id', $tenantId)
            ->where('kind', 'leave');
    }
}
