<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;

final class ManagerDashboardService
{
    public function assemble(User $user): array
    {
        $employee = $user->employee;
        $teamIds = $this->getTeamEmployeeIds($employee);

        return [
            'team_attendance' => $this->teamAttendanceToday($teamIds),
            'pending_approvals' => $this->pendingApprovalsCount($user, $teamIds),
            'team_on_leave' => $this->teamOnLeaveThisWeek($teamIds),
            'team_size' => count($teamIds),
        ];
    }

    private function getTeamEmployeeIds($employee): array
    {
        if (! $employee) {
            return [];
        }

        return Employee::query()
            ->where('supervisor_id', $employee->id)
            ->pluck('id')
            ->toArray();
    }

    private function teamAttendanceToday(array $teamIds): array
    {
        if (empty($teamIds)) {
            return ['present' => 0, 'absent' => 0, 'late' => 0];
        }

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereDate('date', Carbon::today())
            ->get();

        $present = $records->count();
        $late = $records->where('status', 'late')->count();

        return [
            'present' => $present,
            'absent' => count($teamIds) - $present,
            'late' => $late,
        ];
    }

    private function pendingApprovalsCount(User $user, array $teamIds): array
    {
        $leaveCount = 0;
        if (! empty($teamIds)) {
            $employeeIds = $teamIds;
            $leaveCount = LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', LeaveStatus::PENDING)
                ->count();
        }

        return [
            'leave' => $leaveCount,
            'total' => $leaveCount,
        ];
    }

    private function teamOnLeaveThisWeek(array $teamIds): array
    {
        if (empty($teamIds)) {
            return [];
        }

        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        return LeaveRequest::query()
            ->whereIn('employee_id', $teamIds)
            ->where('status', LeaveStatus::APPROVED)
            ->where('start_date', '<=', $weekEnd)
            ->where('end_date', '>=', $weekStart)
            ->with('employee:id,name')
            ->get()
            ->map(fn ($lr) => [
                'employee_name' => $lr->employee?->name,
                'start_date' => $lr->start_date->format('Y-m-d'),
                'end_date' => $lr->end_date->format('Y-m-d'),
            ])
            ->toArray();
    }
}
