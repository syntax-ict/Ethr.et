<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * The last time a terminal that only sends posted something that was not an
 * access event, kept where its admin can see it.
 *
 * Nobody has observed whether this terminal model sends anything when idle.
 * Such a post already counts as contact (`last_sync_at`), but nothing said
 * which contacts were punches and which were not, so a keep-alive, if there
 * is one, could not be told apart from a punch. This records only that, for
 * a person to read on the device page.
 *
 * Measurement only: the status rule (`HikvisionPushAdapter::statusFor`) does
 * not read it. It may, once a terminal has been seen doing it.
 */
final class TerminalKeepAlive
{
    public const TTL_DAYS = 30;

    /** A terminal that posts every few seconds needs one write a minute to be seen. */
    private const REWRITE_AFTER_SECONDS = 60;

    public static function note(Device $device): void
    {
        try {
            $key = self::key($device);
            $last = Cache::get($key);

            if (is_string($last) && now()->diffInSeconds($last, true) < self::REWRITE_AFTER_SECONDS) {
                return;
            }

            Cache::put($key, now()->toIso8601String(), now()->addDays(self::TTL_DAYS));
        } catch (\Throwable) {
            // A downed cache must not turn an acknowledged post into a 500.
        }
    }

    /** ISO 8601, or null when the terminal has not been seen doing it. */
    public static function last(Device $device): ?string
    {
        try {
            $value = Cache::get(self::key($device));
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private static function key(Device $device): string
    {
        return 'device-webhook:keepalive:'.$device->id;
    }
}
