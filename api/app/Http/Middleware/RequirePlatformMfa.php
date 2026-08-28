<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform operator may not change anything without MFA enabled.
 *
 * The control plane is the highest-value account in the product: `admin.manage`
 * reaches across every tenant, and impersonation mints a session as any tenant
 * admin. Impersonation already demanded MFA at the point of use — but nothing
 * else did, so a super admin with `mfa_enabled = false` could still suspend a
 * tenant, cancel a subscription, rewrite the platform bank account every tenant
 * pays into, or flush the failed-job queue, behind a password alone. Any
 * multi-tenant SaaS security baseline treats that as unacceptable.
 *
 * Scope is deliberate: **reads stay open, writes are blocked.**
 *
 * - Blocking reads too would be stricter, and is the eventual goal, but it locks
 *   an un-enrolled operator out of the console entirely — including the screen
 *   that tells them why. Enrolment lives outside this group
 *   (`POST /auth/mfa/setup` → `/auth/mfa/enable`), so the path forward is always
 *   reachable; the console shows a banner explaining it.
 * - Blocking writes is what actually protects tenants: every destructive or
 *   cross-tenant-visible action on this surface is a write.
 *
 * Set `PLATFORM_MFA_ENFORCEMENT=strict` to extend the requirement to reads once
 * every operator account is enrolled.
 */
class RequirePlatformMfa
{
    /** @var list<string> */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Ending an impersonation session is not an operator action.
     *
     * It sits under /admin only because that is where it was registered; the
     * caller is the *impersonated* tenant user, who has no reason to hold
     * platform MFA and frequently does not. Gating it here stranded them inside
     * someone else's account with no way out — caught by ImpersonationTest,
     * which is exactly what that suite is for. The endpoint is already
     * restricted to callers holding an impersonation-ability token.
     *
     * @var list<string>
     */
    private const EXEMPT_PATHS = ['api/v1/admin/exit-impersonation'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->mfa_enabled) {
            return $next($request);
        }

        if ($request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        $strict = config('auth.platform_mfa_enforcement') === 'strict';

        if (! $strict && in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        return response()->json([
            'type' => 'https://ethr.et/errors/mfa-required',
            'title' => 'MFA Required',
            'status' => 403,
            'detail' => __('auth.platform_mfa_required'),
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
