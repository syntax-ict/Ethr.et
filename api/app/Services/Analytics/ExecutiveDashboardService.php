<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
                'history' => array_map(
                    fn ($r) => ['month' => $r['period'], 'value' => $r['gross_cents']],
                    $payroll,
                ),
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

    private function headcount(int $tenantId, ?int $branchId): array
    {
        $active = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        $total = $this->scopedEmployees($tenantId, $branchId)->count();

        $byDepartment = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('department_id, count(*) as count')
            ->groupBy('department_id')
            ->with('department:id,public_id,name')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department?->name ?? 'Unassigned',
                'department_public_id' => $r->department?->public_id,
                'count' => $r->count,
            ]);

        return [
            'total' => $total,
            'active' => $active,
            'by_department' => $byDepartment,
        ];
    }

    private function attendanceRate(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $activeCount = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        if ($activeCount === 0) {
            return ['today' => 0, 'period' => 0];
        }

        $todayQuery = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', Carbon::today());
        $this->joinBranch($todayQuery, $branchId);
        $todayPresent = $todayQuery->distinct()->count('attendance_records.employee_id');

        $workingDays = max(1, $from->diffInWeekdays($to));

        $periodQuery = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to);
        $this->joinBranch($periodQuery, $branchId);
        $periodPresent = $periodQuery->count();

        return [
            'today' => round(($todayPresent / $activeCount) * 100, 1),
            'period' => round(($periodPresent / ($activeCount * $workingDays)) * 100, 1),
        ];
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

    private function payrollSummary(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        if ($branchId === null) {
            $currentRun = PayrollRun::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereDate('period_start', '>=', $from)
                ->whereDate('period_end', '<=', $to)
                ->orderByDesc('period_start')
                ->first();

            $previousFrom = $from->copy()->subMonth();
            $previousTo = $to->copy()->subMonth();

            $previousRun = PayrollRun::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereDate('period_start', '>=', $previousFrom)
                ->whereDate('period_end', '<=', $previousTo)
                ->orderByDesc('period_start')
                ->first();

            return [
                'gross_cents' => $currentRun?->gross_total_cents ?? 0,
                'net_cents' => $currentRun?->net_total_cents ?? 0,
                'tax_cents' => $currentRun?->tax_total_cents ?? 0,
                'previous_gross_cents' => $previousRun?->gross_total_cents ?? 0,
                'employee_count' => $currentRun?->employee_count ?? 0,
            ];
        }

        // Branch-scoped: the run's precomputed totals are tenant-wide, so sum
        // directly from entries joined to the branch's employees instead.
        $current = $this->branchPayrollTotals($tenantId, $branchId, $from, $to);
        $previous = $this->branchPayrollTotals(
            $tenantId,
            $branchId,
            $from->copy()->subMonth(),
            $to->copy()->subMonth(),
        );

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
            ->join('payroll_runs', 'payroll_entries.payroll_run_id', '=', 'payroll_runs.id')
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->where('employees.branch_id', $branchId)
            ->whereDate('payroll_runs.period_start', '>=', $from)
            ->whereDate('payroll_runs.period_end', '<=', $to)
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
        $exits = $this->scopedEmployees($tenantId, $branchId)
            ->whereIn('status', [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED])
            ->whereDate('updated_at', '>=', $from)
            ->whereDate('updated_at', '<=', $to)
            ->count();

        $avgHeadcount = $this->scopedEmployees($tenantId, $branchId)->count();

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
            $month = Carbon::now()->subMonths($i);
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
            ->when($branchId !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($eq) => $eq->where('branch_id', $branchId),
            ))
            ->get();

        $totalEntitled = $balances->sum('entitled_days');
        $totalUsed = $balances->sum('used_days');
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
            ->whereDate('attendance_records.date', '<=', $to);
        $this->joinBranch($query, $branchId);

        return $query
            ->selectRaw('attendance_records.date, count(distinct attendance_records.employee_id) as present')
            ->groupBy('attendance_records.date')
            ->orderBy('attendance_records.date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date instanceof Carbon ? $r->date->format('Y-m-d') : $r->date,
                'present' => $r->present,
            ])
            ->toArray();
    }

    private function attendanceByDepartment(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
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
                'department' => $r->department,
                'present' => $r->present,
            ])
            ->toArray();
    }

    private function attendanceBySource(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to);
        $this->joinBranch($query, $branchId);

        return $query
            ->selectRaw('attendance_records.source, count(*) as count')
            ->groupBy('attendance_records.source')
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source->value,
                'count' => $r->count,
            ])
            ->toArray();
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
                'late_count' => $r->late_count,
            ])
            ->toArray();
    }

    private function monthlyPayrollTrend(int $tenantId, ?int $branchId): array
    {
        if ($branchId === null) {
            return PayrollRun::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['completed', 'approved'])
                ->orderBy('period_start')
                ->limit(12)
                ->get()
                ->map(fn ($r) => [
                    'period' => $r->period_label,
                    'gross_cents' => $r->gross_total_cents,
                    'net_cents' => $r->net_total_cents,
                    'tax_cents' => $r->tax_total_cents,
                ])
                ->toArray();
        }

        return PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_entries.tenant_id', $tenantId)
            ->join('payroll_runs', 'payroll_entries.payroll_run_id', '=', 'payroll_runs.id')
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->where('employees.branch_id', $branchId)
            ->whereIn('payroll_runs.status', ['completed', 'approved'])
            ->selectRaw('
                payroll_runs.id as run_id,
                payroll_runs.period_label as period,
                payroll_runs.period_start as period_start,
                sum(payroll_entries.gross_cents) as gross_cents,
                sum(payroll_entries.net_cents) as net_cents,
                sum(payroll_entries.income_tax_cents) as tax_cents
            ')
            ->groupBy('payroll_runs.id', 'payroll_runs.period_label', 'payroll_runs.period_start')
            ->orderBy('payroll_runs.period_start')
            ->limit(12)
            ->get()
            // These are selectRaw aliases, not real PayrollEntry columns —
            // getAttribute() reads them without asserting a static property
            // that doesn't exist on the model's real schema.
            ->map(fn ($r) => [
                'period' => $r->getAttribute('period'),
                'gross_cents' => (int) $r->getAttribute('gross_cents'),
                'net_cents' => (int) $r->getAttribute('net_cents'),
                'tax_cents' => (int) $r->getAttribute('tax_cents'),
            ])
            ->toArray();
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
            ->whereIn('payroll_runs.status', ['completed', 'approved']);

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
                'period' => $row['period'],
                'overtime_cents' => $row['overtime_cents'],
                'overtime_minutes' => $row['overtime_minutes'],
            ])
            ->values()
            ->toArray();
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
        $runs = PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->pluck('id');

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
                'department' => $r->department,
                'total_gross_cents' => (int) $r->total_gross,
                'employee_count' => $r->employee_count,
            ])
            ->toArray();
    }

    private function payrollByCostCenter(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $runs = PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->pluck('id');

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
                'cost_center' => $r->cost_center,
                'total_gross_cents' => (int) $r->total_gross,
                'employee_count' => $r->employee_count,
            ])
            ->toArray();
    }

    private function payrollTotals(int $tenantId, Carbon $from, Carbon $to, ?int $branchId): array
    {
        if ($branchId === null) {
            $runs = PayrollRun::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereDate('period_start', '>=', $from)
                ->whereDate('period_end', '<=', $to)
                ->get();

            return [
                'total_gross_cents' => $runs->sum('gross_total_cents'),
                'total_net_cents' => $runs->sum('net_total_cents'),
                'total_tax_cents' => $runs->sum('tax_total_cents'),
                'run_count' => $runs->count(),
            ];
        }

        $totals = $this->branchPayrollTotals($tenantId, $branchId, $from, $to);

        $runCount = PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
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
            $month = Carbon::now()->subMonths($i);
            $count = $this->scopedEmployees($tenantId, $branchId)
                ->whereDate('hire_date', '<=', $month->endOfMonth())
                ->whereNotIn('status', [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED])
                ->count();

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
                'count' => $r->count,
            ])
            ->toArray();
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
                'count' => $r->count,
            ])
            ->toArray();
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

        return array_map(fn ($k, $v) => ['bucket' => $k, 'count' => $v], array_keys($buckets), array_values($buckets));
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
}
