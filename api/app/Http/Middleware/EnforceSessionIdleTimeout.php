<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Services\Auth\SessionCookie;
use App\Support\TenantSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * End a browser session that has been idle longer than the tenant allows.
 *
 * `session_timeout_minutes` was saved and read by nothing (audit N6). The
 * browser signs in twice over: `LoginRequest` calls `Auth::login()`, so
 * Sanctum's stateful guard authenticates every same-origin request from the
 * Laravel session (as a `TransientToken`) before it looks at the bearer token
 * in the `access_token` cookie. The session is therefore what keeps a browser
 * signed in, and it is what this times out. Bearer tokens — API clients, and
 * the cookie token whenever the session is gone — are timed out separately,
 * on `last_used_at`, by `SessionIdleTimeout::tokenIsIdle()` in the Sanctum
 * guard.
 *
 * Activity is the time of the last authenticated request that was not
 * background polling. The SPA marks its polls (the 30-second unread-count
 * query on every page, the device and payroll status polls) with
 * `X-ETHR-Background: 1`; without that, an open tab would keep the session
 * alive forever and the setting would mean "close the tab". A poll is still
 * *checked* — an expired session is ended by the poll that finds it — it just
 * does not count as the user being there. The header is a courtesy from our own
 * client, not a security boundary: the timeout protects an unattended session,
 * and a caller that wants to keep its own session alive can simply make
 * requests.
 *
 * Platform users have no tenant policy and are never timed out here; the
 * server-wide `SESSION_LIFETIME` still applies to them as to everyone.
 */
final class EnforceSessionIdleTimeout
{
    public const SESSION_KEY = 'auth.last_activity_at';

    public const BACKGROUND_HEADER = 'X-ETHR-Background';

    public const PROBLEM_TYPE = 'https://ethr.et/errors/session-expired';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Session-authenticated means the web guard holds the user — exactly
        // the case in which Sanctum hands back a TransientToken. A request
        // authenticated by a bearer token is the guard callback's to judge.
        if ($user === null || ! $request->hasSession() || Auth::guard('web')->user() === null) {
            return $next($request);
        }

        $policy = TenantSecurityPolicy::forUser($user);
        if ($policy === null) {
            return $next($request);
        }

        $session = $request->session();
        $lastActivity = $session->get(self::SESSION_KEY);
        $now = now()->getTimestamp();

        if (is_int($lastActivity) && $now - $lastActivity > $policy->idleTimeoutMinutes() * 60) {
            return $this->expire($request);
        }

        if (! is_int($lastActivity) || $request->header(self::BACKGROUND_HEADER) !== '1') {
            $session->put(self::SESSION_KEY, $now);
        }

        return $next($request);
    }

    /**
     * Sign the browser out completely: the session, and the bearer token in
     * its cookie — which would otherwise authenticate the very next request,
     * since Sanctum falls back to it once the session has no user.
     */
    private function expire(Request $request): Response
    {
        // AuthenticateFromCookie has already promoted the cookie to the
        // Authorization header; read either, in case it has not.
        $plain = $request->bearerToken() ?? $request->cookie(SessionCookie::NAME);
        if (is_string($plain) && $plain !== '') {
            PersonalAccessToken::findToken($plain)?->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        app(SessionCookie::class)->clear();

        return response()->json([
            'type' => self::PROBLEM_TYPE,
            'title' => 'Session Expired',
            'status' => 401,
            'detail' => __('auth.session_idle_expired'),
        ], 401)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
