<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;

/**
 * Who may ask for, read and decide overtime in advance. The same shape as
 * `AttendanceCorrectionPolicy`: an employee asks for themselves; someone who
 * may enter attendance for others (`attendance.manage`) may ask on behalf of
 * an employee they reach; deciding is the permission plus reach.
 *
 * Method names carry no dot so `Gate::before` never intercepts them.
 */
class OvertimeRequestPolicy
{
    public function file(User $user, Employee $employee): bool
    {
        if (! $user->hasPermission('overtime.request')) {
            return false;
        }

        if ($user->employee_id !== null && $employee->id === $user->employee_id) {
            return true;
        }

        return $user->hasPermission('attendance.manage') && $this->reaches($user, $employee);
    }

    public function decide(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermission('overtime.approve')
            && $this->reaches($user, $request->employee);
    }

    /** The employee it is for, or whoever asked on their behalf, while it waits. */
    public function cancel(User $user, OvertimeRequest $request): bool
    {
        return ($user->employee_id !== null && $request->employee_id === $user->employee_id)
            || ($request->requested_by !== null && $request->requested_by === $user->id);
    }

    private function reaches(User $user, mixed $employee): bool
    {
        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        return $employee instanceof Employee && $user->canAccessEmployee($employee);
    }
}
