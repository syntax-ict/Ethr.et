<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;

/**
 * Who owns an organisation, and handing it on.
 *
 * The owner is the person ETHR's support calls and the only one who may give
 * ownership away; until 2026-11-04 there was no such field, and "the first
 * active administrator" stood in for it. Every change is an audit-log entry,
 * which is the ownership history the console shows: the names are recorded
 * as they were, so the history reads the same after someone leaves.
 */
final class OrganisationOwnership
{
    /** Whether `$user` owns their organisation. */
    public static function owns(User $user, ?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->owner_user_id !== null && $tenant->owner_user_id === $user->id;
    }

    public function transfer(Tenant $tenant, ?User $from, User $to, ?string $reason = null, bool $byPlatform = false): void
    {
        $tenant->forceFill(['owner_user_id' => $to->id])->save();

        // Recorded under the organisation that changed hands, whatever the
        // request resolved: the console reaches it from elsewhere.
        $current = app(CurrentTenant::class);
        $previous = $current->get();
        $current->set($tenant);

        try {
            $this->record($tenant, $from, $to, $reason, $byPlatform);
        } finally {
            $previous === null ? $current->forget() : $current->set($previous);
        }
    }

    private function record(Tenant $tenant, ?User $from, User $to, ?string $reason, bool $byPlatform): void
    {
        AuditLog::record('tenant.ownership_transferred', $tenant, array_filter([
            'from_public_id' => $from?->public_id,
            'from_name' => $from?->name,
            'to_public_id' => $to->public_id,
            'to_name' => $to->name,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'by_platform' => $byPlatform ?: null,
        ], fn ($value) => $value !== null));
    }
}
