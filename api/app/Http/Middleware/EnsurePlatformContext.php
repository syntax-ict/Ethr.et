<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentTenant;
use App\Support\TenancyDomain;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administration runs with no tenant context, or not at all.
 *
 * The nginx split refuses the admin *console* (`/admin`) on any host but
 * admin.ethr.et — but the admin *API* lives under `/api/v1/admin`, which that
 * rule deliberately does not match, so it stayed reachable from every tenant
 * hostname. Authorization still refused the data (`Gate::authorize('admin.manage')`
 * is on every action), so this is defence in depth rather than a plugged leak:
 * it means a future admin endpoint added without a gate cannot be called from a
 * tenant host at all.
 *
 * "Platform context" is expressed as *no tenant resolved* rather than as a
 * hostname comparison, because that is the property the code below actually
 * depends on: AdminTenantController reaches across tenants with
 * withoutGlobalScopes(), which is only safe while no tenant scope is in play.
 * It also falls out correctly for free — `admin` is a reserved subdomain, so
 * admin.ethr.et never resolves a tenant, while habru.ethr.et always does.
 *
 * Inert without APP_DOMAIN. Single-host development has no platform hostname,
 * and the SPA sends X-Tenant there, so enforcing this would refuse the admin
 * console on localhost — the same reasoning as ResolveTenant's X-Tenant gate.
 */
class EnsurePlatformContext
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        // On a tenant's own hostname these routes do not exist. Via
        // TenancyDomain, not a bare `=== null` check: `APP_DOMAIN=` in a .env
        // yields an empty string, and treating that as "configured" turned
        // every admin API call on a tenant host into a 404.
        if (TenancyDomain::isHostnameAuthoritative() && $this->currentTenant->resolved()) {
            return $this->notAvailable();
        }

        // Anywhere else, only a super admin uses the platform API, in every
        // mode. Until this, "no tenant resolved" was the whole test and the
        // ability gate was the only wall: when a custom role could carry
        // `admin.manage`, a tenant user on the apex with no X-Tenant walked
        // through (audit N86). This is the second wall that finding asked for
        // (audit N93), with the 403 the gate always gave. An unauthenticated
        // request is left to auth:sanctum's 401.
        $user = $request->user();
        if ($user !== null && ! $user->isSuperAdmin()) {
            throw new AuthorizationException;
        }

        return $next($request);
    }

    /**
     * 404 rather than 403: to a tenant user these routes are not "forbidden",
     * they are not part of their application at all, and saying so would
     * confirm the platform API's shape to someone probing for it.
     */
    private function notAvailable(): Response
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Platform administration is not available on this host.',
        ], 404)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
