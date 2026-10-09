<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Dotenv\Dotenv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the HTTP-driven release steps (`/api/v1/maintenance/*`) with a
 * shared secret. The same shape as VerifyCronToken, with three differences
 * that follow from what these routes can do:
 *
 *   - its own secret, MAINTENANCE_TOKEN, meant to be set for a release and
 *     emptied afterwards;
 *   - the header only, never the query string. The cron routes accept
 *     `?token=` because Plesk's "Fetch a URL" task cannot set headers; a
 *     person running a release step can, and a URL ends up in access logs;
 *   - its own attempt limit, counted in the FILE cache before the token is
 *     compared. The routes sit outside the api group, so `api-global` does
 *     not apply, and the database cache store has no table before the first
 *     migration.
 */
class VerifyMaintenanceToken
{
    public const HEADER = 'X-Maintenance-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->expectedToken();

        // Fail closed and invisible: unconfigured means not there at all.
        if ($expected === '') {
            abort(404);
        }

        if (strlen($expected) < (int) config('maintenance.min_token_length', 32)) {
            logger()->error('MAINTENANCE_TOKEN is shorter than the configured minimum; maintenance routes disabled.', [
                'min_token_length' => (int) config('maintenance.min_token_length', 32),
            ]);

            abort(404);
        }

        if ($this->tooManyAttempts($request)) {
            abort(429);
        }

        $presented = (string) $request->header(self::HEADER, '');

        if (! hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
            logger()->warning('Rejected maintenance request with a bad or missing token.', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            abort(404);
        }

        return $next($request);
    }

    /**
     * The token as `.env` says it is NOW. Once `optimize` has run
     * `config:cache`, config() holds the value from that moment, so emptying
     * MAINTENANCE_TOKEN afterwards would not switch these routes off. With a
     * cached configuration the file is read on each request instead, so the
     * edit takes effect at once — which is what "empty it after the release"
     * relies on.
     */
    private function expectedToken(): string
    {
        $readFile = config('maintenance.read_env_file') ?? app()->configurationIsCached();

        if (! $readFile) {
            return (string) config('maintenance.token', '');
        }

        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $values = Dotenv::parse((string) file_get_contents($path));

        return (string) ($values['MAINTENANCE_TOKEN'] ?? '');
    }

    /**
     * Counts every request, good or bad, per client address per minute.
     */
    private function tooManyAttempts(Request $request): bool
    {
        $cache = Cache::store((string) config('maintenance.cache_store', 'file'));
        $key = 'maintenance-attempts:'.hash('sha256', (string) $request->ip()).':'.intdiv(time(), 60);

        $cache->add($key, 0, 120);
        $attempts = (int) $cache->increment($key);

        return $attempts > (int) config('maintenance.attempts_per_minute', 5);
    }
}
