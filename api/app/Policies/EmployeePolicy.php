<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

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

    public function updateFinancial(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.updateFinancial')
            && $user->canAccessEmployee($employee);
    }

    public function transition(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.transition')
            && $user->canAccessEmployee($employee);
    }
}
