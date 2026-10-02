<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\ImpersonationToken;
use App\Support\TenantSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In a tenant whose MFA policy is "required", a user without MFA may do one
 * thing: enrol.
 *
 * The policy was saved and never read (audit N6) — an admin chose "Required"
 * and nobody was asked to set anything up. Now every authenticated request
 * from an un-enrolled user of such a tenant is refused with a 403 whose `type`
 * the SPA recognises and answers by opening the two-factor setup screen.
 *
 * Why refuse rather than block the sign-in itself: the user has to be signed
 * in to enrol — `POST /auth/mfa/setup` and `/enable` are authenticated, and
 * enrolment proves possession of the authenticator by verifying a code against
 * the secret, which needs a session to attach it to. So the session is issued
 * and confined to the account-security surface below, the same shape
 * `RejectUnverifiedMfaToken` uses for a sign-in that still owes a code.
 *
 * Reachable while confined — the user's own account security and nothing else:
 *
 *   GET  /auth/me                 render the shell and the setup screen
 *   POST /auth/logout, /refresh   leave, or keep the session alive while enrolling
 *   POST /auth/mfa/setup, enable  enrol
 *   POST /auth/password/change    the setup screen also offers it, and an
 *                                 expired password is advised at the same time
 *   GET  /auth/sessions, devices  the rest of that screen (and revoking them)
 *
 * Fail-closed: the list is explicit, so an endpoint added later is refused.
 *
 * Not affected:
 *   - platform users — no tenant, no tenant policy (`TenantSecurityPolicy`);
 *   - an impersonation session — the operator behind it passed platform MFA to
 *     start it, and `BlockImpersonatedActions` forbids them enrolling MFA on the
 *     impersonated account, so confining them would leave nothing they could do.
 */
final class RequireTenantMfaEnrolment
{
    public const PROBLEM_TYPE = 'https://ethr.et/errors/mfa-enrolment-required';

    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/refresh',
        'api/v1/auth/mfa/setup',
        'api/v1/auth/mfa/enable',
        'api/v1/auth/password/change',
        'api/v1/auth/sessions',
        'api/v1/auth/sessions/*',
        'api/v1/auth/devices',
        'api/v1/auth/devices/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->mfa_enabled) {
            return $next($request);
        }

        // Checked by name, as BlockImpersonatedActions does: the token also
        // holds '*', which a `can()` check would match for anything.
        $token = $request->user()?->currentAccessToken();
        if ($token && in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        if (! TenantSecurityPolicy::forUser($user)?->requiresMfa()) {
            return $next($request);
        }

        return response()->json([
            'type' => self::PROBLEM_TYPE,
            'title' => 'MFA Enrolment Required',
            'status' => 403,
            'detail' => __('auth.mfa_enrolment_required'),
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
