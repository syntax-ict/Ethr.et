<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\AttendanceConflict;
use App\Models\Employee;
use App\Models\User;

/**
 * Who may resolve an attendance conflict.
 *
 * Resolving voids one of the employee's two punches, so it is a decision
 * about that employee's hours and follows `AttendanceCorrectionPolicy::decide`:
 * the permission, plus the employee inside the caller's org scope. It used to
 * check `attendance.resolveConflicts` alone, while the conflict list was
 * already limited to the caller's scope — so a custom role scoped to direct
 * reports could resolve, by public id, conflicts it was not allowed to see.
 *
 * It also refuses the caller's own conflict: choosing which of your own two
 * punches counts is choosing your own hours, which leave and correction
 * approval already refuse.
 *
 * The method name carries no dot so `Gate::before` (which answers only for
 * known permission names) never intercepts it.
 */
class AttendanceConflictPolicy
{
    public function resolve(User $user, AttendanceConflict $conflict): bool
    {
        if (! $user->hasPermission('attendance.resolveConflicts')) {
            return false;
        }

        if ($user->employee_id !== null && $conflict->employee_id === $user->employee_id) {
            return false;
        }

        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        $employee = $conflict->employee;

        return $employee instanceof Employee && $user->canAccessEmployee($employee);
    }
}
