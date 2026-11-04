<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\LeaveStatus;
use App\Enums\OrgScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approval\DecidableApprovals;
use App\Services\CurrentTenant;
use App\Support\TenantTime;

/**
 * "Your department" (or "Your team") on the dashboard: today, for the people a
 * department- or team-scoped reader is responsible for.
 *
 * A department admin had no section of their own: "Organisation today" needs
 * `dashboard.executive`, which the role rightly lost in #561, and "Today"
 * counts direct reports, which a department head usually has none of, so the
 * page told them "No direct reports" (dashboard review, 2026-11-03). The
 * people counted here are exactly the ones `scopeAccessibleEmployees()`
 * reaches, so the section can never show more than /employees does.
 */
final class DepartmentDashboardService
{
    public function __construct(
        private readonly ManagerDashboardService $manager,
        private readonly DecidableApprovals $approvals,
    ) {}

    public function assemble(User $user): array
    {
        $scope = $user->orgScope();
        $ids = $user->scopeAccessibleEmployees(Employee::query())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $employee = $user->employee;
        /** @var Department|Team|null $unit */
        $unit = match ($scope) {
            OrgScope::DEPARTMENT => $employee?->department,
            OrgScope::TEAM => $employee?->team,
            default => null,
        };

        return [
            'scope' => $scope->value,
            'unit' => $unit === null ? null : [
                'name' => $unit->name,
                'name_am' => $unit->name_am,
            ],
            'headcount' => count($ids),
            'attendance' => $this->manager->attendanceToday($ids),
            'on_leave_today' => $this->onLeaveToday($ids),
            'pending_approvals' => (int) $this->approvals->counts($user)['total'],
        ];
    }

    /** @param  list<int>  $ids */
    private function onLeaveToday(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $today = TenantTime::today(app(CurrentTenant::class)->get())->format('Y-m-d');

        return LeaveRequest::query()
            ->whereIn('employee_id', $ids)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();
    }
}
