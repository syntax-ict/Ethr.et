<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('holiday.viewAny');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holiday.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('holiday.create');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holiday.update');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holiday.delete');
    }
}
