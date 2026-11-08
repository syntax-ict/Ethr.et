<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function request(User $user): bool
    {
        return $user->hasPermission('leave.request');
    }

    /**
     * Filing for someone else: whoever administers leave balances (HR), for
     * an employee inside their org scope. The approval engine never lets the
     * filer approve what they filed.
     */
    public function requestFor(User $user, ?Employee $employee): bool
    {
        if (! $employee instanceof Employee || ! $user->hasPermission('leave.request') || ! $user->hasPermission('leave.adjustBalance')) {
            return false;
        }

        return $user->orgScope() === OrgScope::ALL || $user->canAccessEmployee($employee);
    }

    /**
     * Cancelling or shortening approved leave (A-Z review E-02): whoever
     * administers leave balances, for a person inside their org scope, as for
     * filing on someone's behalf. Changing one's own leave is refused in the
     * controller, with its own message.
     */
    public function recall(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->hasPermission('leave.adjustBalance')) {
            return false;
        }

        return $user->orgScope() === OrgScope::ALL || ($leaveRequest->employee !== null && $user->canAccessEmployee($leaveRequest->employee));
    }

    public function viewTeam(User $user): bool
    {
        return $user->hasPermission('leave.viewTeam');
    }

    public function viewAll(User $user): bool
    {
        return $user->hasPermission('leave.viewAll');
    }

    public function viewTypes(User $user): bool
    {
        return $user->hasPermission('leave.viewTypes');
    }

    public function manageTypes(User $user): bool
    {
        return $user->hasPermission('leave.manageTypes');
    }

    /**
     * Your own balance, anyone's with leave.viewAll, and the people your
     * scope reaches with leave.viewTeam: a supervisor deciding a report's
     * request reads the balance it draws on.
     */
    public function viewBalance(User $user, Employee $employee): bool
    {
        if ($user->employee_id !== null && $employee->id === $user->employee_id) {
            return true;
        }

        if ($user->hasPermission('leave.viewAll')) {
            return true;
        }

        return $user->hasPermission('leave.viewTeam') && $user->canAccessEmployee($employee);
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        // Owners can always view their own request.
        if ($user->employee_id !== null && $leaveRequest->employee_id === $user->employee_id) {
            return true;
        }

        if ($user->hasPermission('leave.viewAll')) {
            return true;
        }

        if (! $user->hasPermission('leave.viewTeam')) {
            return false;
        }

        if (! $leaveRequest->relationLoaded('employee')) {
            $leaveRequest->load('employee');
        }

        return $leaveRequest->employee && $user->canAccessEmployee($leaveRequest->employee);
    }

    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->hasPermission('leave.approve')) {
            return false;
        }

        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        if (! $leaveRequest->relationLoaded('employee')) {
            $leaveRequest->load('employee');
        }

        return $leaveRequest->employee && $user->canAccessEmployee($leaveRequest->employee);
    }
}
