<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShiftRotation;
use App\Models\User;

/**
 * Reuses the `shift.*` permissions rather than introducing `rotation.*`. A
 * rotation is a way of scheduling shifts, so the roles that may manage shifts
 * are exactly the roles that may manage rotations — a parallel permission set
 * would have to be granted to every role that already has the shift ones, and
 * would silently lock rotations away from existing custom roles.
 */
class ShiftRotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shift.viewAny');
    }

    public function view(User $user, ShiftRotation $rotation): bool
    {
        return $user->hasPermission('shift.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shift.create');
    }

    public function update(User $user, ShiftRotation $rotation): bool
    {
        return $user->hasPermission('shift.update');
    }

    public function delete(User $user, ShiftRotation $rotation): bool
    {
        return $user->hasPermission('shift.delete');
    }
}
