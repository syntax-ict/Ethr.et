<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

/**
 * Driver-agnostic cache invalidation for the executive dashboard.
 *
 * The dashboard endpoints are keyed by date range, so there's no fixed set
 * of keys a listener could Cache::forget() directly. Instead every cached
 * key embeds the tenant's current version; bumping it makes all previously
 * cached entries unreachable without needing Cache::tags(), which only
 * Redis/Memcached support.
 */
class DashboardCacheVersion
{
    public static function current(int $tenantId): int
    {
        return (int) Cache::get(self::key($tenantId), 0);
    }

    public static function bump(int $tenantId): void
    {
        Cache::forever(self::key($tenantId), self::current($tenantId) + 1);
    }

    private static function key(int $tenantId): string
    {
        return "dashboard_cache_version:{$tenantId}";
    }
}
