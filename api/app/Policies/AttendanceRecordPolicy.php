<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrgScope;
use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.viewAll');
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        if (! $user->hasPermission('attendance.view')) {
            return false;
        }

        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        if (! $record->relationLoaded('employee')) {
            $record->load('employee');
        }

        return $record->employee && $user->canAccessEmployee($record->employee);
    }

    public function viewOwn(User $user): bool
    {
        return $user->hasPermission('attendance.viewOwn');
    }

    public function viewTeam(User $user): bool
    {
        return $user->hasPermission('attendance.viewTeam');
    }

    public function checkIn(User $user): bool
    {
        return $user->hasPermission('attendance.checkIn');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('attendance.manage');
    }
}
