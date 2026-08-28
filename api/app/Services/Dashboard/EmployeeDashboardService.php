<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\OnboardingProgress;
use App\Models\PayrollEntry;
use App\Models\User;
use Carbon\Carbon;

final class EmployeeDashboardService
{
    public function assemble(User $user): array
    {
        $employee = $user->employee;
        $tenantId = $employee?->tenant_id ?? $user->tenant_id;

        return [
            'attendance_today' => $this->todayAttendance($employee),
            'leave_balances' => $this->leaveBalances($employee),
            'latest_payslip' => $this->latestPayslip($employee),
            'upcoming_holidays' => $this->upcomingHolidays($tenantId),
            'pending_approvals' => 0,
            'tenant_summary' => $this->tenantSummary($tenantId),
            'onboarding_complete' => $this->isOnboardingComplete($tenantId),
        ];
    }

    // $tenantId is nullable for the same reason $employee is: a super admin has
    // neither an employee profile nor a tenant, and this endpoint carries no
    // gate, so it is reachable by one. The rest of this class already degrades
    // to null/empty rather than failing, so these do too — previously a super
    // admin hitting the employee dashboard got a 500 out of upcomingHolidays().
    private function tenantSummary(?int $tenantId): array
    {
        if ($tenantId === null) {
            return ['employee_count' => 0, 'department_count' => 0, 'branch_count' => 0];
        }

        return [
            'employee_count' => Employee::where('tenant_id', $tenantId)->count(),
            'department_count' => Department::where('tenant_id', $tenantId)->count(),
            'branch_count' => Branch::where('tenant_id', $tenantId)->count(),
        ];
    }

    private function isOnboardingComplete(?int $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        $progress = OnboardingProgress::where('tenant_id', $tenantId)->first();

        return $progress?->completed_at !== null;
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

    private function upcomingHolidays(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }

        return Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', Carbon::today())
            ->orderBy('date')
            ->limit(3)
            ->get()
            // name_am ships alongside name so an Amharic UI can render the Amharic
            // holiday name. HolidayService already stores both ("Ethiopian New Year
            // (Enkutatash)" / "እንቁጣጣሽ"); dropping it here was why the Ethiopian
            // calendar — the product's signature feature — read in English.
            ->map(fn ($h) => [
                'name' => $h->name,
                'name_am' => $h->name_am,
                'date' => $h->date->format('Y-m-d'),
            ])
            ->toArray();
    }
}
