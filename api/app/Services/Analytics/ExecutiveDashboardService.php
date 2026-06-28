<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\PayrollRun;
use Carbon\Carbon;

final class ExecutiveDashboardService
{
    public function overview(int $tenantId, Carbon $from, Carbon $to): array
    {
        return [
            'headcount' => $this->headcount($tenantId),
            'attendance_rate' => $this->attendanceRate($tenantId, $from, $to),
            'payroll_summary' => $this->payrollSummary($tenantId, $from, $to),
            'turnover' => $this->turnoverRate($tenantId, $from, $to),
            'workforce_growth' => $this->workforceGrowth($tenantId),
            'leave_utilization' => $this->leaveUtilization($tenantId),
        ];
    }

    public function attendanceDeepDive(int $tenantId, Carbon $from, Carbon $to): array
    {
        return [
            'daily_trend' => $this->dailyAttendanceTrend($tenantId, $from, $to),
            'by_department' => $this->attendanceByDepartment($tenantId, $from, $to),
            'by_source' => $this->attendanceBySource($tenantId, $from, $to),
            'top_late' => $this->topLateEmployees($tenantId, $from, $to),
        ];
    }

    public function payrollDeepDive(int $tenantId, Carbon $from, Carbon $to): array
    {
        return [
            'monthly_trend' => $this->monthlyPayrollTrend($tenantId),
            'by_department' => $this->payrollByDepartment($tenantId, $from, $to),
            'totals' => $this->payrollTotals($tenantId, $from, $to),
        ];
    }

    public function workforceDeepDive(int $tenantId): array
    {
        return [
            'headcount_trend' => $this->headcountTrend($tenantId),
            'by_department' => $this->headcountByDepartment($tenantId),
            'by_gender' => $this->genderDistribution($tenantId),
            'by_tenure' => $this->tenureDistribution($tenantId),
        ];
    }

    private function headcount(int $tenantId): array
    {
        $query = Employee::withoutGlobalScope('tenant')->where('tenant_id', $tenantId);

        $active = (clone $query)->whereIn('status', [
            EmployeeStatus::HIRED,
            EmployeeStatus::PROBATION,
            EmployeeStatus::CONFIRMED,
        ])->count();

        $total = (clone $query)->count();

        $byDepartment = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
            ->selectRaw('department_id, count(*) as count')
            ->groupBy('department_id')
            ->with('department:id,name')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department?->name ?? 'Unassigned',
                'count' => $r->count,
            ]);

        return [
            'total' => $total,
            'active' => $active,
            'by_department' => $byDepartment,
        ];
    }

    private function attendanceRate(int $tenantId, Carbon $from, Carbon $to): array
    {
        $activeCount = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
            ->count();

        if ($activeCount === 0) {
            return ['today' => 0, 'period' => 0];
        }

        $todayPresent = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', Carbon::today())
            ->distinct()
            ->count('employee_id');

        $workingDays = max(1, $from->diffInWeekdays($to));

        $periodPresent = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->count();

        return [
            'today' => round(($todayPresent / $activeCount) * 100, 1),
            'period' => round(($periodPresent / ($activeCount * $workingDays)) * 100, 1),
        ];
    }

    private function payrollSummary(int $tenantId, Carbon $from, Carbon $to): array
    {
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

    private function turnoverRate(int $tenantId, Carbon $from, Carbon $to): array
    {
        $exits = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED])
            ->whereDate('updated_at', '>=', $from)
            ->whereDate('updated_at', '<=', $to)
            ->count();

        $avgHeadcount = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->count();

        $rate = $avgHeadcount > 0 ? round(($exits / $avgHeadcount) * 100, 1) : 0;

        return [
            'exits' => $exits,
            'rate' => $rate,
        ];
    }

    private function workforceGrowth(int $tenantId): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $hires = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
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

    private function leaveUtilization(int $tenantId): array
    {
        $year = Carbon::now()->year;

        $balances = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
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

    private function dailyAttendanceTrend(int $tenantId, Carbon $from, Carbon $to): array
    {
        return AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw('date, count(distinct employee_id) as present')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date instanceof Carbon ? $r->date->format('Y-m-d') : $r->date,
                'present' => $r->present,
            ])
            ->toArray();
    }

    private function attendanceByDepartment(int $tenantId, Carbon $from, Carbon $to): array
    {
        return AttendanceRecord::withoutGlobalScope('tenant')
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereDate('attendance_records.date', '>=', $from)
            ->whereDate('attendance_records.date', '<=', $to)
            ->join('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('departments.name as department, count(distinct attendance_records.employee_id) as present')
            ->groupBy('departments.name')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department,
                'present' => $r->present,
            ])
            ->toArray();
    }

    private function attendanceBySource(int $tenantId, Carbon $from, Carbon $to): array
    {
        return AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw('source, count(*) as count')
            ->groupBy('source')
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source instanceof \BackedEnum ? $r->source->value : (string) $r->source,
                'count' => $r->count,
            ])
            ->toArray();
    }

    private function topLateEmployees(int $tenantId, Carbon $from, Carbon $to): array
    {
        return AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->where('status', 'late')
            ->selectRaw('employee_id, count(*) as late_count')
            ->groupBy('employee_id')
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

    private function monthlyPayrollTrend(int $tenantId): array
    {
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

    private function payrollByDepartment(int $tenantId, Carbon $from, Carbon $to): array
    {
        $runs = PayrollRun::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->pluck('id');

        if ($runs->isEmpty()) {
            return [];
        }

        return \App\Models\PayrollEntry::withoutGlobalScope('tenant')
            ->whereIn('payroll_run_id', $runs)
            ->join('employees', 'payroll_entries.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
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

    private function payrollTotals(int $tenantId, Carbon $from, Carbon $to): array
    {
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

    private function headcountTrend(int $tenantId): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $count = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
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

    private function headcountByDepartment(int $tenantId): array
    {
        return Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
            ->selectRaw('department_id, count(*) as count')
            ->groupBy('department_id')
            ->with('department:id,name')
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department?->name ?? 'Unassigned',
                'count' => $r->count,
            ])
            ->toArray();
    }

    private function genderDistribution(int $tenantId): array
    {
        return Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
            ->selectRaw('gender, count(*) as count')
            ->groupBy('gender')
            ->get()
            ->map(fn ($r) => [
                'gender' => $r->gender,
                'count' => $r->count,
            ])
            ->toArray();
    }

    private function tenureDistribution(int $tenantId): array
    {
        $employees = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED])
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
}
