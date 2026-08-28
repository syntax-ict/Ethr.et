<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentTenant;
use App\Support\TenancyDomain;
use Closure;
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
        // Via TenancyDomain, not a bare `=== null` check: `APP_DOMAIN=` in a .env
        // yields an empty string, and treating that as "configured" turned every
        // admin API call on a tenant host into a 404.
        if (! TenancyDomain::isHostnameAuthoritative()) {
            return $next($request);
        }

        if (! $this->currentTenant->resolved()) {
            return $next($request);
        }

        // 404 rather than 403: on a tenant host these routes are not "forbidden",
        // they are not part of that application at all, and saying so would
        // confirm the platform API's shape to a tenant user probing for it.
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Platform administration is not available on this host.',
        ], 404)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
