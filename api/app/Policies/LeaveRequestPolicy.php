<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function request(User $user): bool
    {
        return $user->hasPermission('leave.request');
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
