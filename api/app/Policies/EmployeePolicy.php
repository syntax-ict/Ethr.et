<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.viewAny');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.view')
            && $user->canAccessEmployee($employee);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.update')
            && $user->canAccessEmployee($employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.delete')
            && $user->canAccessEmployee($employee);
    }

    public function viewFinancial(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.viewFinancial')
            && $user->canAccessEmployee($employee);
    }

    public function updateFinancial(User $user, Employee $employee): Response
    {
        if (! $user->hasPermission('employee.updateFinancial') || ! $user->canAccessEmployee($employee)) {
            return Response::deny();
        }

        // Your own salary, tax number and bank account are changed by someone
        // else, as a profile change you file is reviewed by someone else. The
        // organisation admin has no one above them, so they are not refused.
        if ($employee->id === $user->employee_id && ! $user->isAtLeast(UserRole::TENANT_ADMIN)) {
            return Response::deny(__('employee.cannot_change_own_pay'));
        }

        return Response::allow();
    }

    public function issueActivationCode(User $user, Employee $employee): bool
    {
        return $user->hasPermission('users.issueActivationCode')
            && $user->canAccessEmployee($employee);
    }

    public function transition(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.transition')
            && $user->canAccessEmployee($employee);
    }
}
