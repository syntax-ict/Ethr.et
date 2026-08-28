<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class CustomRolePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('settings.manage');
    }
}
