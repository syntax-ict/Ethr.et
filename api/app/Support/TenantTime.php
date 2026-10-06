<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turning a time a person typed into the instant it means.
 *
 * Timestamps are stored in UTC (APP_TIMEZONE) and shown in the tenant's own
 * zone, `tenants.timezone`. A wall-clock "09:00" entered for an Addis Ababa
 * workforce is therefore 06:00 UTC. Parsed in the app timezone it became
 * 09:00 UTC and was shown back as 12:00 — every manual and imported punch
 * three hours late (audit N4).
 *
 * Eloquent writes a Carbon by formatting it in its *own* zone, without
 * converting, so the result here is converted to UTC before it is returned;
 * handing a +03:00 instant to a model would store the wall clock again.
 */
final class TenantTime
{
    /** What `tenants.timezone` defaults to, and what ProvisionTenant sets. */
    public const DEFAULT_ZONE = 'Africa/Addis_Ababa';

    /**
     * The tenant's zone. `UpdateOrganizationRequest` accepts any string up to
     * 50 characters, so a value PHP does not know falls back to the default
     * rather than throwing on every punch.
     */
    public static function zone(?Tenant $tenant): string
    {
        $zone = $tenant?->timezone;

        if (! is_string($zone) || $zone === '') {
            return self::DEFAULT_ZONE;
        }

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return self::DEFAULT_ZONE;
        }

        return $zone;
    }

    /**
     * The UTC instant of a wall-clock date and `H:i` time in the given zone.
     */
    public static function wallClockToUtc(string $date, string $time, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $zone)
            ->setTimeFromTimeString($time)
            ->utc();
    }
}
