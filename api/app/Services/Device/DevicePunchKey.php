<?php

declare(strict_types=1);

namespace App\Services\Device;

/**
 * The one place a device punch key is made to fit `attendance_records.idempotency_key`
 * (varchar 64). A key of 64 characters or less is returned unchanged, so every
 * punch already recorded still dedupes; a longer one becomes `dk:` plus 60 hex
 * characters of its sha256. Applying it twice gives the same result.
 */
final class DevicePunchKey
{
    public const MAX = 64;

    public static function fit(string $key): string
    {
        if (mb_strlen($key) <= self::MAX) {
            return $key;
        }

        return 'dk:'.substr(hash('sha256', $key), 0, 60);
    }
}
