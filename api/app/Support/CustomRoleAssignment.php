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
 * so the screen can never offer a role the server then refuses (audit N87,
 * N89). A super admin may give any role. Anyone else may give a role only if
 * they hold every permission it grants and do not reach less far than it.
 */
final class CustomRoleAssignment
{
    public static function mayAssign(User $actor, CustomRole $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        $granted = Permission::permissionsForCustomRole($role->id);
        $reach = OrgScope::tryFrom((string) $role->org_scope) ?? OrgScope::SELF;

        return array_diff($granted, $actor->permissionNames()) === []
            && $reach->breadth() <= $actor->orgScope()->breadth();
    }
}
