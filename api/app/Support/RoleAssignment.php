<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Who may hand out which built-in role, in one place.
 *
 * User management applied three rules and the add-employee form's "create
 * login" one of them, so a role refused on one screen could be granted on
 * the other (A-Z review C-01, 2026-11-03). Every path that gives an account a
 * role asks here.
 *
 * - `super_admin` is a platform role and is never handed out by a tenant.
 * - Nobody grants a role above their own level.
 * - Two roles share level 70 with different jobs (audit N95): whoever
 *   processes payroll must not also change the accounts it pays into. Below
 *   the organisation admin, the other role at your level is not yours to
 *   hand out, or an HR admin could open a Finance admin account for
 *   themselves.
 */
final class RoleAssignment
{
    /**
     * The translation key saying why $actor may not give $role, or null when
     * they may.
     */
    public static function refusal(User $actor, UserRole $role): ?string
    {
        if ($role === UserRole::SUPER_ADMIN) {
            return 'user.errors.role_not_assignable';
        }

        if ($actor->isSuperAdmin()) {
            return null;
        }

        if ($role->level() > $actor->role->level()) {
            return 'user.errors.role_above_your_level';
        }

        if ($actor->role->level() < UserRole::TENANT_ADMIN->level()
            && $role->level() === $actor->role->level() && $role !== $actor->role) {
            return 'user.errors.role_beside_your_own';
        }

        return null;
    }
}
