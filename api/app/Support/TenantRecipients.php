<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The people in one organisation who hold a role and can still sign in.
 *
 * Queued listeners and jobs resolve no tenant, so `BelongsToTenant`'s fail-closed
 * scope would add `whereRaw('0 = 1')` and the lookup would match nobody, silently.
 * Only that scope is dropped (`SoftDeletes` stays, so a removed user is never
 * returned), and `tenant_id` is stated on the same chain, unconditionally. The
 * caller derives `$tenantId` from a record that is itself tenant-owned (the
 * device or the payroll run), never from the row being fetched.
 */
final class TenantRecipients
{
    /**
     * @param  list<UserRole>  $roles
     * @return Collection<int, User>
     */
    public static function withRoles(int $tenantId, array $roles): Collection
    {
        return User::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', array_map(fn (UserRole $r) => $r->value, $roles))
            ->where('status', 'active')
            ->get();
    }
}
