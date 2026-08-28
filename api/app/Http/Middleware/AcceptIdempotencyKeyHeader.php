<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the documented `Idempotency-Key` header satisfy the `idempotency_key` rule.
 *
 * CLAUDE.md convention #10 and the API conventions block both specify a header:
 *
 *     Idempotency: Idempotency-Key: {uuid} (on all write endpoints)
 *
 * but every FormRequest validates a body field, and nothing in the codebase read
 * the header at all. An integrator following the published contract got a 422 on
 * all eight attendance capture methods and on payroll processing.
 *
 * This copies the header into the input when the body field is absent, so:
 *
 *   - the header now works, as documented;
 *   - the body field keeps working, so the existing frontend and every existing
 *     test are untouched;
 *   - the body wins if both are sent, because an explicit field in the payload is
 *     the more specific intent — and silently preferring the header would change
 *     behaviour for callers already sending both.
 *
 * Only the value is moved here. Replay detection stays where it already lives, in
 * the services that own each write.
 */
class AcceptIdempotencyKeyHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        $header = $request->header('Idempotency-Key');

        if (filled($header) && blank($request->input('idempotency_key'))) {
            $request->merge(['idempotency_key' => $header]);
        }

        return $next($request);
    }
}
