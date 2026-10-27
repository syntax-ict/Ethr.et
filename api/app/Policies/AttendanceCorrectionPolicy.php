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
        if ($user->employee_id !== null && $record->employee_id === $user->employee_id) {
            return $user->hasPermission('correction.create');
        }

        return $this->fileFor($user, $record->employee);
    }

    /**
     * Filing for an employee rather than a record: a missed-punch claim has
     * no record to point at, so the same rule is asked of the person.
     */
    public function fileFor(User $user, ?Employee $employee): bool
    {
        if (! $employee instanceof Employee || ! $user->hasPermission('correction.create')) {
            return false;
        }

        if ($user->employee_id !== null && $employee->id === $user->employee_id) {
            return true;
        }

        if (! $user->hasPermission('attendance.manage')) {
            return false;
        }

        return $this->reaches($user, $employee);
    }

    public function decide(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission('correction.approve')
            && $this->reaches($user, $correction->employee);
    }

    /**
     * The preview states the employee's hourly rate, which is their salary
     * over 176 hours, so it needs what reading a salary needs. Until
     * 2026-10-20 every supervisor could derive their reports' pay here.
     */
    public function previewImpact(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission('correction.viewPending')
            && $user->hasPermission('employee.viewFinancial')
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
