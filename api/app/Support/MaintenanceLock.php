<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * The one lock every task that changes the host holds: the release-step
 * maintenance endpoints and the console's "Back up now" take this same name
 * on the same store, so two of them can never overlap. It is in the file
 * cache because the database one may not have its table yet.
 */
final class MaintenanceLock
{
    public const NAME = 'maintenance:run';

    /** The lock, not yet taken; the caller does get() and release(). */
    public static function make(): Lock
    {
        $store = Cache::store((string) config('maintenance.cache_store', 'file'))->getStore();
        abort_unless($store instanceof LockProvider, 500, 'The maintenance cache store cannot hold a lock.');

        return $store->lock(self::NAME, (int) config('maintenance.lock_seconds', 300));
    }
}
