<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Carbon;

/**
 * How far a device's clock is from the server's.
 *
 * Two sources, which do not say the same thing:
 *
 * - **A heartbeat** is stamped with the terminal's clock when it is sent, so
 *   its time against now is the clock error, whatever its size: six hours
 *   (a terminal set to Ethiopian time of day) and seven years (set to the
 *   Ethiopian calendar) are exactly what has to be caught. Any offset is
 *   stored. A terminal whose heartbeat carries no time cannot be measured
 *   this way, and says so rather than reading as zero.
 * - **A punch** was made earlier than it arrived, and a terminal that kept
 *   punches through an outage sends them late. A late punch looks like a
 *   clock that is behind, so a punch can only ever prove a clock is **ahead**:
 *   a punch dated after it arrived. A behind reading is never stored, and a
 *   punch never overwrites a heartbeat reading taken in the last hour.
 */
final class DeviceClock
{
    /** Within this a clock is in step: delivery delay and rounding. */
    public const TOLERANCE_SECONDS = 120;

    /** A heartbeat reading this recent is not overwritten by a punch's. */
    public const HEARTBEAT_PRECEDENCE_SECONDS = 3600;

    /** A reading is rewritten no more often than this, unless it moved. */
    private const REWRITE_AFTER_SECONDS = 60;

    /** The column is a 32-bit integer; a clock a lifetime out is clamped, not refused. */
    private const MAX_OFFSET = 2147483647;

    private const SOURCE_TTL_DAYS = 30;

    /**
     * What a punch says of the clock: the offset when the punch is dated after
     * now, else null. The offset is returned even when a fresher heartbeat
     * reading means it is not stored, since the integrity check reads it off
     * the punch.
     *
     * @param  string  $momentUtc  the punch time, already normalised to UTC (DeviceTime)
     */
    public static function observe(Device $device, string $momentUtc): ?int
    {
        $offset = min(self::MAX_OFFSET, self::offsetOf($momentUtc));

        if ($offset <= 0) {
            return null;
        }

        if (! self::heartbeatReadingIsFresh($device)) {
            $device->forceFill([
                'clock_offset_seconds' => $offset,
                'clock_checked_at' => now(),
            ])->save();
            self::rememberSource($device, 'punch');
        }

        return $offset;
    }

    /**
     * Record what a heartbeat says. `$momentUtc` is the time the heartbeat
     * carried, normalised to UTC, or null when it carried none. Returns the
     * offset, or null when there was nothing to measure.
     */
    public static function observeHeartbeat(Device $device, ?string $momentUtc): ?int
    {
        if ($momentUtc === null) {
            self::rememberSource($device, 'heartbeat_no_time');

            return null;
        }

        $offset = max(-self::MAX_OFFSET, min(self::MAX_OFFSET, self::offsetOf($momentUtc)));

        $stored = $device->clock_offset_seconds;
        $checked = $device->clock_checked_at;
        $unchanged = $stored !== null && abs($stored - $offset) < 5
            && $checked !== null && $checked->gt(now()->subSeconds(self::REWRITE_AFTER_SECONDS))
            && self::source($device)['source'] === 'heartbeat';

        if (! $unchanged) {
            $device->forceFill([
                'clock_offset_seconds' => $offset,
                'clock_checked_at' => now(),
            ])->save();
        }

        self::rememberSource($device, 'heartbeat');

        return $offset;
    }

    public static function isAhead(?int $offset): bool
    {
        return $offset !== null && $offset > self::TOLERANCE_SECONDS;
    }

    /**
     * Where the stored reading came from, for the device page.
     *
     * - `source`: `heartbeat`, `punch`, `heartbeat_no_time` (heartbeats carry
     *   no time and nothing else has measured the clock), or null when
     *   nothing has been seen.
     * - `measurable`: false when the terminal sends heartbeats with no time and
     *   no punch has shown its clock ahead, so the page says it cannot be
     *   measured and never prints a zero; true when there is a reading; null
     *   when nothing is known yet.
     *
     * @return array{source: string|null, measurable: bool|null}
     */
    public static function describe(Device $device): array
    {
        $source = self::source($device)['source'];
        $hasReading = $device->clock_offset_seconds !== null;

        if ($source === 'heartbeat_no_time') {
            return $hasReading
                ? ['source' => 'punch', 'measurable' => true]
                : ['source' => 'heartbeat_no_time', 'measurable' => false];
        }

        return ['source' => $source ?? ($hasReading ? 'punch' : null), 'measurable' => $hasReading ? true : null];
    }

    private static function offsetOf(string $momentUtc): int
    {
        return (int) round(Carbon::parse($momentUtc, 'UTC')->getTimestamp() - Carbon::now()->getTimestamp());
    }

    private static function heartbeatReadingIsFresh(Device $device): bool
    {
        $source = self::source($device);

        return $source['source'] === 'heartbeat'
            && $source['at'] !== null
            && Carbon::parse($source['at'])->gt(now()->subSeconds(self::HEARTBEAT_PRECEDENCE_SECONDS));
    }

    /** @return array{source: string|null, at: string|null} */
    private static function source(Device $device): array
    {
        try {
            $value = HealthSignals::store()->get(self::sourceKey($device));
        } catch (\Throwable) {
            return ['source' => null, 'at' => null];
        }

        return is_array($value) && isset($value['source'])
            ? ['source' => (string) $value['source'], 'at' => isset($value['at']) ? (string) $value['at'] : null]
            : ['source' => null, 'at' => null];
    }

    /**
     * Kept in the signals store (a release's `optimize:clear` leaves it), not a column: a lost entry only means a punch may
     * overwrite a heartbeat reading it would have left alone, and the page
     * says "not measurable" until the next heartbeat. Written no more than
     * once a minute, as a terminal's heartbeat arrives every few seconds.
     */
    private static function rememberSource(Device $device, string $source): void
    {
        try {
            $key = self::sourceKey($device);
            $last = HealthSignals::store()->get($key);

            if (is_array($last) && ($last['source'] ?? null) === $source && isset($last['at'])
                && Carbon::parse((string) $last['at'])->gt(now()->subSeconds(self::REWRITE_AFTER_SECONDS))) {
                return;
            }

            HealthSignals::store()->put($key, ['source' => $source, 'at' => now()->toIso8601String()], now()->addDays(self::SOURCE_TTL_DAYS));
        } catch (\Throwable) {
            // A downed cache must not turn an acknowledged post into a 500.
        }
    }

    private static function sourceKey(Device $device): string
    {
        return 'device-clock:source:'.$device->id;
    }
}
