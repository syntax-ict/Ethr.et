<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Application;

/**
 * Drops the config and route caches that predate the deployed release.
 *
 * Plesk Git deploys tracked files only, and `bootstrap/cache/*.php` is
 * gitignored, so a deploy never replaces a stale cache: a route added by the
 * release answers 404 until someone runs `optimize`, which needs a token only
 * the owner holds. This runs from bootstrap/app.php, before configuration or
 * routes load, so the first request after a deploy already runs the new code.
 *
 * The rule is the one OperationsStatusService reports: a cache file whose
 * mtime is earlier than `built_at` in `public/release.json` is stale. A marker
 * records what was dropped, so the next scheduler tick rebuilds the caches
 * (CronRunner) and an owner's deliberate `clear` is never undone.
 *
 * Nothing here may throw: the site must stay up when the sweep cannot work.
 */
final class StaleReleaseCaches
{
    /** The commands that rebuild what the sweep drops. Views recompile by mtime. */
    public const RECACHE = ['config:cache', 'route:cache'];

    /**
     * @return list<string> the cache files removed
     */
    public static function sweep(Application $app): array
    {
        try {
            $files = self::cacheFiles($app);
            $present = array_filter($files, 'is_file');

            if ($present === []) {
                return [];
            }

            $builtAt = self::builtAt($app);

            if ($builtAt === null) {
                return [];
            }

            // Act once per release. If the host's clock is behind CI, a cache the
            // tick rebuilds carries an mtime older than built_at and would be
            // swept again on every request, forever. The tick still deletes the
            // recache marker after a successful rebuild whatever the mtimes say.
            $swept = is_file(self::sweptPath($app)) ? @file_get_contents(self::sweptPath($app)) : false;

            if ($swept !== false && trim($swept) === $builtAt['raw']) {
                return [];
            }

            $removed = [];

            foreach ($present as $file) {
                $modified = is_file($file) ? filemtime($file) : false;

                if ($modified !== false && $modified < $builtAt['timestamp'] && @unlink($file)) {
                    $removed[] = $file;
                }
            }

            if ($removed !== []) {
                @file_put_contents(self::markerPath($app), $builtAt['raw'], LOCK_EX);
                if (! is_dir(self::sweptPath($app))) {
                    @file_put_contents(self::sweptPath($app), $builtAt['raw'], LOCK_EX);
                }
            }

            return $removed;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function sweptPath(Application $app): string
    {
        return $app->bootstrapPath('cache/ethr-swept-release');
    }

    public static function markerPath(Application $app): string
    {
        return $app->bootstrapPath('cache/ethr-recache-pending');
    }

    /**
     * @return list<string>
     */
    public static function cacheFiles(Application $app): array
    {
        return [$app->getCachedConfigPath(), $app->getCachedRoutesPath()];
    }

    /**
     * @return array{raw: string, timestamp: int}|null null when there is no usable release stamp
     */
    public static function builtAt(Application $app): ?array
    {
        $raw = ReleaseStamp::fromFile($app->publicPath('release.json'))['built_at'];

        if ($raw === null) {
            return null;
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : ['raw' => $raw, 'timestamp' => $timestamp];
    }

    /** Both caches exist and were built at or after the release. */
    public static function cachesAreFresh(Application $app): bool
    {
        $builtAt = self::builtAt($app);

        foreach (self::cacheFiles($app) as $file) {
            $modified = is_file($file) ? filemtime($file) : false;

            if ($modified === false || ($builtAt !== null && $modified < $builtAt['timestamp'])) {
                return false;
            }
        }

        return true;
    }
}
