<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\Tenant;
use Carbon\Carbon;
use Throwable;

/**
 * The moment a device says a punch happened, as a UTC ISO-8601 string.
 *
 * Time-and-attendance terminals (ZKTeco, Suprema, most generic devices) keep
 * a wall clock and report `2026-10-13 08:01:00` with no offset. The engine
 * parsed that in the application zone, UTC, so in Addis Ababa every such
 * punch was stored three hours late - an 08:01 arrival read as 11:01 and
 * rated late. A timestamp that states its offset (`Z`, `+03:00`) is taken as
 * stated; one that does not is read in the device's zone, or the
 * organisation's when the device has none.
 */
final class DeviceTime
{
    /** How far ahead of the server a device clock may run before its punch is refused. */
    public const MAX_FUTURE_MINUTES = 10;

    public static function normalize(string $raw, Device $device): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $moment = self::hasOffset($raw)
                ? Carbon::parse($raw)
                : Carbon::parse($raw, self::zone($device));
        } catch (Throwable) {
            return null;
        }

        return $moment->utc()->toIso8601String();
    }

    /** Whether the device's clock puts this punch in the future. */
    public static function isInFuture(string $utc): bool
    {
        return Carbon::parse($utc)->gt(Carbon::now('UTC')->addMinutes(self::MAX_FUTURE_MINUTES));
    }

    public static function zone(Device $device): string
    {
        $zone = $device->getAttribute('timezone');
        if (is_string($zone) && in_array($zone, timezone_identifiers_list(), true)) {
            return $zone;
        }

        return TenantTime::zone(Tenant::query()->find($device->tenant_id));
    }

    private static function hasOffset(string $raw): bool
    {
        // A trailing Z, or ±hh:mm / ±hhmm after the time part. A bare date's
        // hyphens are not an offset, so only the part after the "T" or space
        // that separates date from time is read.
        $time = preg_split('/[T ]/', $raw, 2)[1] ?? '';

        return str_ends_with(strtoupper($raw), 'Z')
            || preg_match('/[+-]\d{2}:?\d{2}$/', $time) === 1;
    }
}
