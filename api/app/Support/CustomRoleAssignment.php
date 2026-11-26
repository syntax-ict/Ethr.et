<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrgScope;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\User;

/**
 * Who may hand out which custom role, in one place.
 *
 * The validation of an assignment (`ValidatesCustomRoleAssignment`) and the
 * list the Users page offers (`GET /users/assignable-roles`) both ask here,
 * so the two cannot disagree about the role itself (audit N87, N89). Whether
 * the person receiving it may be changed by this actor is a separate check, on
 * the built-in role levels.
 *
 * A super admin may give any role. So may whoever holds `settings.manage`:
 * they can already create a role with any ability a tenant may hold and edit
 * any role to add more (`/roles` checks nothing but the platform-only list), so
 * the subset rule below could only stop them giving a role they were free to
 * build, and it stopped the organisation admin giving the role that carries
 * `dashboard.regional`, which no built-in role holds. Anyone else may give a
 * role only if they hold every permission it grants and do not reach less far
 * than it, so a person with `users.update` alone cannot hand out more than
 * they hold (audit N87).
 */
final class CustomRoleAssignment
{
    public static function mayAssign(User $actor, CustomRole $role): bool
    {
        if ($actor->isSuperAdmin() || $actor->hasPermission('settings.manage')) {
            return true;
        }

        $granted = Permission::permissionsForCustomRole($role->id);
        $reach = OrgScope::tryFrom((string) $role->org_scope) ?? OrgScope::SELF;

        return array_diff($granted, $actor->permissionNames()) === []
            && $reach->breadth() <= $actor->orgScope()->breadth();
    }
}
