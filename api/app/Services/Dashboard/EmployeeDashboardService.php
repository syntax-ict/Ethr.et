<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\User;
use Carbon\Carbon;

final class EmployeeDashboardService
{
    public function assemble(User $user): array
    {
        $employee = $user->employee;

        return [
            'attendance_today' => $this->todayAttendance($employee),
            'leave_balances' => $this->leaveBalances($employee),
            'latest_payslip' => $this->latestPayslip($employee),
            'upcoming_holidays' => $this->upcomingHolidays($employee?->tenant_id ?? $user->tenant_id),
            'pending_approvals' => 0,
        ];
    }

    private function todayAttendance($employee): ?array
    {
        if (! $employee) {
            return null;
        }

        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today())
            ->first();

        if (! $record) {
            return ['status' => 'not_checked_in'];
        }

        return [
            'status' => $record->check_out ? 'checked_out' : 'checked_in',
            'check_in' => $record->check_in,
            'check_out' => $record->check_out,
            'worked_minutes' => $record->check_out
                ? (int) Carbon::parse($record->check_in)->diffInMinutes(Carbon::parse($record->check_out))
                : null,
        ];
    }

    private function leaveBalances($employee): array
    {
        if (! $employee) {
            return [];
        }

        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', Carbon::now()->year)
            ->with('leaveType')
            ->orderByDesc('entitled_days')
            ->limit(3)
            ->get()
            ->map(fn ($b) => [
                'type' => $b->leaveType?->name,
                'entitled' => $b->entitled_days,
                'used' => $b->used_days,
                'remaining' => $b->remainingDays(),
            ])
            ->toArray();
    }

    private function latestPayslip($employee): ?array
    {
        if (! $employee) {
            return null;
        }

        $entry = PayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->with('payrollRun')
            ->orderByDesc('created_at')
            ->first();

        if (! $entry) {
            return null;
        }

        return [
            'period' => $entry->payrollRun?->period_label,
            'net_cents' => $entry->net_cents,
            'gross_cents' => $entry->gross_cents,
        ];
    }

    private function upcomingHolidays(int $tenantId): array
    {
        return Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', Carbon::today())
            ->orderBy('date')
            ->limit(3)
            ->get()
            ->map(fn ($h) => [
                'name' => $h->name,
                'date' => $h->date->format('Y-m-d'),
            ])
            ->toArray();
    }
}
