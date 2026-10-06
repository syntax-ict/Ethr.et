<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\CurrentTenant;
use App\Support\TenancyDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second half of the tenancy contract ResolveTenant describes.
 *
 * ResolveTenant deliberately derives tenant identity from the host/header and
 * never from the authenticated user, so that a leaked token cannot drag its own
 * tenant context along with it. That is only safe if something then checks the
 * two agree — and nothing did. Any authenticated user could read another
 * tenant's data simply by aiming the same valid token at that tenant's
 * subdomain (or sending its X-Tenant header): the global scope faithfully
 * scoped every query to the *host's* tenant, and no layer asked whether the
 * caller belonged to it.
 *
 * So this runs after auth:sanctum and enforces the missing half.
 *
 * Super admins are exempt: they are deliberately tenant-less and have to reach
 * across tenants for the platform admin screens and to start an impersonation.
 * An impersonation token is issued *as the target user*, so it carries that
 * user's tenant_id and is checked here like any other.
 */
class EnsureUserBelongsToTenant
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // auth:sanctum runs first, so this is belt-and-braces; let the auth
        // layer own the unauthenticated response rather than inventing one.
        if ($user === null) {
            return $next($request);
        }

        // No tenant resolved: BelongsToTenant's global scope already degrades to
        // whereRaw('0 = 1'), so tenant-scoped data returns empty rather than
        // leaking. Nothing to enforce. This is also the branch that lets a super
        // admin work on the platform host, where `admin` is reserved and so no
        // tenant ever resolves.
        if (! $this->currentTenant->resolved()) {
            return $next($request);
        }

        if ($user->role === UserRole::SUPER_ADMIN) {
            // Single-host development has no platform hostname to confine them
            // to, so the exemption stays broad there. Via TenancyDomain because
            // `APP_DOMAIN=` yields an empty string rather than null, and reading
            // that as "configured" would refuse super admins on every host.
            if (! TenancyDomain::isHostnameAuthoritative()) {
                return $next($request);
            }

            // With hostnames in play the exemption narrows to the platform host,
            // which the "not resolved" branch above already covers. Reaching
            // here means a super admin is on a *tenant's* hostname, so they fall
            // through to the same comparison as anyone else — and since their
            // tenant_id is null by design, that refuses them.
            //
            // A super admin has no business browsing a tenant's own hostname:
            // the sanctioned route to tenant data is impersonation, which issues
            // a token *as that tenant's user* and is MFA-gated, time-boxed and
            // audited. Letting the platform account roam tenant hosts directly
            // would bypass all three.
        }

        if ($this->currentTenant->id() === $user->tenant_id) {
            return $next($request);
        }

        // 403 rather than 404: the tenant's existence is already public (it is
        // in the hostname the caller just used), so there is no existence
        // oracle to protect here.
        return response()->json([
            'type' => 'https://ethr.et/errors/tenant-mismatch',
            'title' => 'Tenant Mismatch',
            'status' => 403,
            'detail' => 'Your account does not belong to this tenant.',
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
