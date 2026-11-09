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
 *   the organisation admin, no role at your level is yours to hand out:
 *   not the other one, or an HR admin could open a Finance admin account
 *   for themselves, and not your own, or they could open a second HR admin
 *   account and change their own pay from it.
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

        // Your own role too, not only the other one at your level: an HR
        // admin who could open a second HR admin account, with no employee
        // record behind it, edited their own pay and bank account from it,
        // past the rule that nobody changes their own (review 2026-11-09).
        if ($actor->role->level() < UserRole::TENANT_ADMIN->level()
            && $role->level() === $actor->role->level()) {
            return 'user.errors.role_beside_your_own';
        }

        return null;
    }
}
