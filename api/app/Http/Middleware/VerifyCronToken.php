<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the HTTP-driven scheduler and queue routes with a shared secret.
 *
 * WHY A SHARED SECRET AND NOT A SIGNED URL. Laravel's signed URLs carry an
 * expiry, which is the right shape for a link you mint per use. The caller
 * here is a "fetch this URL every minute" task configured once, in a panel
 * field, that cannot compute an HMAC. A static signed URL would either never
 * expire — the same durability as a secret, with more moving parts — or break
 * the day it lapsed, silently, which is the failure mode this whole workstream
 * keeps finding.
 *
 * THE HEADER ONLY. `?token=` was accepted for Plesk's "Fetch a URL" task,
 * which sets no headers, until 2026-10-31. That caller never existed: the
 * account has no Scheduled Tasks section (G0-D), and both real callers,
 * GitHub Actions and cron-job.org, send the header. A token in the query
 * string reaches access logs and Referer headers, so it is no longer read
 * (owner decision 2026-10-31).
 *
 * The comparison is `hash_equals` over SHA-256 digests: constant-time, and
 * equal-length regardless of what was submitted, so neither the value nor its
 * length leaks through timing.
 */
class VerifyCronToken
{
    public const HEADER = 'X-Cron-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('cron.token', '');

        // Fail closed, and fail INVISIBLE. 404 rather than 401: an
        // unconfigured deployment should not confirm that these routes exist.
        if ($expected === '') {
            abort(404);
        }

        // A token too short to withstand guessing is a configuration error,
        // not an authentication failure. Refuse loudly in the log (never with
        // the value) and 404 outward.
        if (strlen($expected) < (int) config('cron.min_token_length', 32)) {
            logger()->error('CRON_TOKEN is shorter than the configured minimum; cron routes disabled.', [
                'min_token_length' => (int) config('cron.min_token_length', 32),
            ]);

            abort(404);
        }

        $presented = (string) $request->header(self::HEADER, '');

        if (! hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
            logger()->warning('Rejected cron request with a bad or missing token.', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            abort(404);
        }

        return $next($request);
    }
}
