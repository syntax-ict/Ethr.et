<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shift.viewAny');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shift.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shift.create');
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shift.update');
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shift.delete');
    }
}
