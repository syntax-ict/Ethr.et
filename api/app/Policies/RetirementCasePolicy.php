<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class RetirementCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('retirement_case.viewAny');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('retirement_case.manage');
    }
}
