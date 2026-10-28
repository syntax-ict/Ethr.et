<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The store that health timestamps live in: the scheduler heartbeat, the
 * cron callers' ticks, the last queue drain (QueueHealth) and when mail last
 * left or failed (Support/Mail/MailHealth).
 *
 * Not the default store. Every release runs `optimize:clear`, which empties
 * the default store, so each of these read "never" in the operations centre
 * right after a deploy: the moment an operator most wants to know whether
 * the release left background work and mail running. The `signals` store
 * (config/cache.php) is a file store in a directory nothing clears.
 */
final class HealthSignals
{
    public const STORE = 'signals';

    /**
     * The default store while the configuration does not define `signals`:
     * production caches its configuration, so between a deploy and the
     * release step that rebuilds it, `Cache::store('signals')` would throw,
     * and the cron endpoint records a tick before it runs anything.
     */
    public static function store(): Repository
    {
        return config('cache.stores.'.self::STORE) === null
            ? Cache::store()
            : Cache::store(self::STORE);
    }
}
