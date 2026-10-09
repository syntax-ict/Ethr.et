<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approval\DecidableApprovals;
use Carbon\Carbon;

final class ManagerDashboardService
{
    public function assemble(User $user): array
    {
        $employee = $user->employee;
        $teamIds = $this->getTeamEmployeeIds($employee);

        return [
            'team_attendance' => $this->teamAttendanceToday($teamIds),
            'pending_approvals' => $this->pendingApprovalsCount($user),
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
        $late = $records->where('status', AttendanceStatus::LATE)->count();

        return [
            'present' => $present,
            'absent' => (int) (count($teamIds) - $present),
            'late' => $late,
        ];
    }

    /**
     * Exactly what the approvals queue lists for this user (audit N63): the
     * count was pending leave from direct reports only, so HR and branch- or
     * department-scoped approvers saw "All caught up!" while the queue had
     * items, and corrections and profile changes were never counted.
     *
     * @return array{leave: int, correction: int, profile_update: int, total: int}
     */
    private function pendingApprovalsCount(User $user): array
    {
        return app(DecidableApprovals::class)->counts($user);
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
            ->all();
    }
}
