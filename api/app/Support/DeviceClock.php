<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Carbon;

/**
 * How far a device's clock is from the server's, read off the punches it
 * pushes.
 *
 * A pushed punch arrives seconds after it was made, so the gap between its
 * time and now is the device's clock error plus a little delivery delay. Two
 * limits keep that reading honest:
 *
 * - A gap of more than an hour is not a clock: it is a backlog the device
 *   kept while it was offline, or a time that is simply wrong. It is not
 *   recorded.
 * - A device that is behind looks the same as a punch that arrived late, so
 *   only running **ahead** is treated as a finding (IntegrityInspector). Behind
 *   is shown, not acted on.
 */
final class DeviceClock
{
    /** Beyond this the gap says nothing about the clock. */
    public const MAX_OBSERVABLE_SECONDS = 3600;

    /** Within this a clock is in step: delivery delay and rounding. */
    public const TOLERANCE_SECONDS = 120;

    /**
     * Record the offset a pushed punch shows. Returns it, or null when the
     * punch says nothing about the clock.
     *
     * @param  string  $momentUtc  the punch time, already normalised to UTC (DeviceTime)
     */
    public static function observe(Device $device, string $momentUtc): ?int
    {
        $offset = (int) round(Carbon::parse($momentUtc, 'UTC')->getTimestamp() - Carbon::now()->getTimestamp());

        if (abs($offset) > self::MAX_OBSERVABLE_SECONDS) {
            return null;
        }

        $device->forceFill([
            'clock_offset_seconds' => $offset,
            'clock_checked_at' => now(),
        ])->save();

        return $offset;
    }

    public static function isAhead(?int $offset): bool
    {
        return $offset !== null && $offset > self::TOLERANCE_SECONDS;
    }
}
