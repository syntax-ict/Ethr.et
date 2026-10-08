<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * The organisation's working week, as ISO weekdays (1 = Monday … 7 = Sunday),
 * from `tenants.settings.working_days`. Monday to Friday until the
 * organisation says otherwise.
 *
 * One reader for the setting: `GET /settings` returned it and nothing that
 * counted days read it, so leave skipped Saturday and Sunday whatever the
 * organisation had set (audit N82).
 */
final class WorkingWeek
{
    public const DEFAULT = [1, 2, 3, 4, 5];

    /** @return list<int> */
    public static function of(?Tenant $tenant): array
    {
        $days = [];
        foreach ($tenant?->settings['working_days'] ?? self::DEFAULT as $day) {
            $days[] = (int) $day;
        }

        return $days;
    }
}
