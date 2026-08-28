<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;

class AttendanceCorrectionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('correction.create');
    }

    public function viewAll(User $user): bool
    {
        return $user->hasPermission('correction.viewAll');
    }

    public function viewPending(User $user): bool
    {
        return $user->hasPermission('correction.viewPending');
    }

    public function approve(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission('correction.approve');
    }
}
