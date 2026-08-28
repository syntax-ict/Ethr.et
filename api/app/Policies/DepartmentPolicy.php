<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('org.viewAny');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->hasPermission('org.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('org.create');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermission('org.update');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermission('org.delete');
    }
}
