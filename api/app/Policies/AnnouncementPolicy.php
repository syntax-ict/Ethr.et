<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class AnnouncementPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('announcement.manage');
    }
}
