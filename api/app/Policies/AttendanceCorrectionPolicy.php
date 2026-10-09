<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;

/**
 * Who may file, read and decide an attendance correction.
 *
 * A correction rewrites punches once approved, so both ends are tied to the
 * employee whose punches they are:
 *
 *  - Filing: an employee files against their own records. Someone holding
 *    `attendance.manage` (HR — the same people who may enter manual punches)
 *    may file on behalf of an employee they can reach through their org scope.
 *    `correction.create` alone is granted to everyone and says nothing about
 *    *whose* record, which is how any employee could file against a colleague's.
 *  - Deciding and previewing: the permission, plus the subject employee inside
 *    the caller's org scope — the rule `LeaveRequestPolicy::approve` applies.
 *    A supervisor acts on their direct reports, HR on everyone.
 *
 * Method names carry no dot so `Gate::before` (which answers only for known
 * permission names) never intercepts them.
 */
class AttendanceCorrectionPolicy
{
    public function file(User $user, AttendanceRecord $record): bool
    {
        if (! $user->hasPermission('correction.create')) {
            return false;
        }

        if ($user->employee_id !== null && $record->employee_id === $user->employee_id) {
            return true;
        }

        if (! $user->hasPermission('attendance.manage')) {
            return false;
        }

        return $this->reaches($user, $record->employee);
    }

    public function decide(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission('correction.approve')
            && $this->reaches($user, $correction->employee);
    }

    public function previewImpact(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission('correction.viewPending')
            && $this->reaches($user, $correction->employee);
    }

    private function reaches(User $user, mixed $employee): bool
    {
        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        return $employee instanceof Employee && $user->canAccessEmployee($employee);
    }
}
