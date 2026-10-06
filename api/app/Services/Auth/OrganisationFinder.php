<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;

/**
 * The organisations an email address can sign in to, for "find my organisation"
 * on the apex login.
 *
 * This is the one deliberate cross-tenant read behind that feature, and it is
 * narrow on purpose:
 *
 * - It runs before authentication, on the apex, where no tenant is resolved —
 *   so the fail-closed `BelongsToTenant` scope would return nothing, and the
 *   question ("which tenants?") is cross-tenant by its nature. The bypass is
 *   pinned in `tests/Feature/Security/tenant-scope-bypasses.php`.
 * - From `users` it reads `tenant_id` and nothing else.
 * - It returns each organisation's subdomain and name and nothing else — no
 *   user id, no role, no count of matches per tenant.
 * - It counts only places the person can actually sign in to: an `active`
 *   account in an operational tenant (`Tenant::operational()`, the query form
 *   of the check `ResolveTenant` refuses inactive tenants with).
 *
 * The result is emailed to the address, never returned to the caller: the
 * endpoint answers every request with the same sentence.
 */
final class OrganisationFinder
{
    /**
     * @return list<array{subdomain: string, name: string}>
     */
    public function forEmail(string $email): array
    {
        // Lowercased and matched against `email_normalized` (a generated
        // LOWER(email) column) rather than `email`: MariaDB's collation
        // compares `email` case-insensitively and SQLite's does not, so an
        // exact match behaved differently in production and in tests. This is
        // also how login matches an email (`AuthIdentifierResolver::byEmail`),
        // so the organisations listed are the ones the address can sign in to.
        $tenantIds = User::withoutGlobalScopes()
            ->select('tenant_id')
            ->where('email_normalized', mb_strtolower($email))
            ->where('status', 'active');

        return Tenant::query()
            ->operational()
            ->whereIn('id', $tenantIds)
            ->orderBy('name')
            ->get(['subdomain', 'name'])
            ->map(fn (Tenant $tenant): array => [
                'subdomain' => (string) $tenant->subdomain,
                'name' => (string) $tenant->name,
            ])
            ->values()
            ->all();
    }
}
