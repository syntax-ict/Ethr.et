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

    // Ending an impersonation used to be exempted here by path, because it was
    // registered under /admin while its caller is the impersonated tenant user.
    // It moved to `POST /auth/impersonation/exit` (audit N19) — under /admin it
    // also sat behind EnsurePlatformContext, which 404s on the tenant host the
    // impersonated session lives on — so the exemption has nothing left to cover.

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->mfa_enabled) {
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
