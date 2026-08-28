<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PersonnelActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('personnel_action.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('personnel_action.create');
    }
}
