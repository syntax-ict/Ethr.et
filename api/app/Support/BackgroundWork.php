<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marks the stretch of a process that is draining the queue or running the
 * scheduler on behalf of nobody.
 *
 * `RunBackgroundWorkAfterResponse` runs both inside an ordinary signed-in
 * request, so `auth()->user()` and the request's address and browser belong to
 * whoever happened to load a page, not to the job's work. `AuditLog::record()`
 * asks `active()` and records no person, address or browser while it is true.
 */
final class BackgroundWork
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        self::$depth++;

        try {
            return $work();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
