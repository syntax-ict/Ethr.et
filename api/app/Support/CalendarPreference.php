<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Which calendar a person's dates are shown in (audit N33).
 *
 * One rule, read by `/auth/me`, `/profile` and `GET /settings`: the user's own
 * choice wins; without one, their organisation's `settings.calendar`; without
 * that, Ethiopian — the calendar `ProvisionTenant` gives every new tenant and
 * the one the frontend shows before anyone has said otherwise.
 *
 * Before this the three disagreed: a user with no stored choice was told
 * "gregorian" while the app displayed Ethiopian, and the organisation setting
 * was never stored anywhere the server could read.
 */
final class CalendarPreference
{
    public const DEFAULT = 'ethiopian';

    /** What an organisation may choose. A user may also store `dual`, which no display implements yet. */
    public const ORGANISATION_CHOICES = ['ethiopian', 'gregorian'];

    public static function forUser(User $user): string
    {
        $stored = is_array($user->preferences) ? ($user->preferences['calendar'] ?? null) : null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        // A platform user has no organisation to inherit from.
        if ($user->tenant_id === null) {
            return self::DEFAULT;
        }

        // loadMissing, not a property read: preventLazyLoading is on outside
        // production, and /auth/me resolves the user without the relation.
        $user->loadMissing('tenant');
        $tenant = $user->getRelation('tenant');

        return self::forTenant($tenant instanceof Tenant ? $tenant : null);
    }

    /** @return 'ethiopian'|'gregorian' */
    public static function forTenant(?Tenant $tenant): string
    {
        // getAttribute(), as TenantSecurityPolicy reads it: the static analyser
        // types the JSON column from the schema and cannot see the array cast.
        $settings = $tenant?->getAttribute('settings');
        $calendar = is_array($settings) ? ($settings['calendar'] ?? null) : null;

        return $calendar === 'gregorian' ? 'gregorian' : self::DEFAULT;
    }
}
