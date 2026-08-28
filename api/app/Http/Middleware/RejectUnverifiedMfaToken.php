<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token that still owes MFA may do exactly one thing: complete MFA.
 *
 * `AuthService` issues a credential at the end of a *password* check, before any
 * second factor. It was created with `['*']` abilities and only a shorter TTL —
 * described in the code as "a short-lived challenge credential, not a full
 * session", which was true of its lifetime and not of its power. A password
 * alone therefore bought a fully privileged five-minute session on every account
 * with MFA enabled, renewable indefinitely by signing in again. Verified against
 * the running stack on 2026-08-22: a pre-MFA token drove a platform-admin write
 * (`POST /admin/failed-jobs/retry-all` → 200) and a cross-tenant user search.
 * That reduced MFA to a prompt the attacker could decline.
 *
 * The token now carries `mfa:verify` and nothing else, and this refuses it
 * everywhere except the endpoints needed to finish or abandon the sign-in:
 *
 *   POST /auth/mfa/verify   exchange the challenge for a real session
 *   POST /auth/logout       abandon it
 *   GET  /auth/me           render the MFA screen (identity only)
 *
 * Fail-closed by construction: the allow-list is explicit, so any endpoint added
 * later is refused to a half-authenticated caller by default.
 */
class RejectUnverifiedMfaToken
{
    public const CHALLENGE_ABILITY = 'mfa:verify';

    /**
     * Set when a password check passed but MFA has not been supplied.
     *
     * Needed in addition to the token ability because `LoginRequest` calls
     * `Auth::login()` on password success, so Sanctum's stateful guard
     * authenticates browser requests as a TransientToken — which has no
     * abilities to restrict. Without this flag the fix would cover API clients
     * and leave the SPA, the way the product actually signs in, wide open.
     */
    public const SESSION_FLAG = 'mfa.pending';

    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'api/v1/auth/mfa/verify',
        'api/v1/auth/logout',
        'api/v1/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return $next($request);
        }

        if (! $this->owesMfa($request)) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        return response()->json([
            'type' => 'https://ethr.et/errors/mfa-incomplete',
            'title' => 'MFA Required',
            'status' => 403,
            'detail' => __('auth.mfa_incomplete'),
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }

    /**
     * Both transports, because the product uses both.
     *
     * An API client presents the challenge *token*, whose abilities say so. A
     * browser is authenticated from the session Sanctum established at password
     * time, and arrives as a TransientToken with no abilities at all — so the
     * session flag is the only thing that can speak for it.
     */
    private function owesMfa(Request $request): bool
    {
        if ($request->hasSession() && $request->session()->get(self::SESSION_FLAG) === true) {
            return true;
        }

        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && in_array(self::CHALLENGE_ABILITY, $token->abilities ?? [], true);
    }
}
