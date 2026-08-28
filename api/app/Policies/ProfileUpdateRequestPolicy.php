<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ProfileUpdateRequestPolicy
{
    /**
     * Reviewing a gated profile change is the same authority as editing the
     * employee record directly — approving one is exactly how the edit lands.
     */
    public function viewPending(User $user): bool
    {
        return $user->hasPermission('employee.update');
    }

    public function review(User $user): bool
    {
        return $user->hasPermission('employee.update');
    }
}
