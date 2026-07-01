<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hybrid tenant resolution strategy.
 *
 * Tenant identity is resolved BEFORE authentication, from explicit URL/header
 * signals only — never from the authenticated user's tenant_id. This keeps
 * authentication and tenant identity as separate, independently-verified
 * concerns (preventing cross-tenant data access via leaked tokens).
 *
 * Resolution order:
 *   1. Subdomain — production primary: acme.ethr.et
 *   2. X-Tenant header — for API consumers, mobile apps, local dev
 *   3. Tenant slug in form body (login/register only) — for shared-URL flow
 *
 * If none of the above identify a tenant, the request proceeds without a
 * tenant context. Routes that need a tenant must rely on the global scope
 * (whereRaw('0 = 1')) returning no results, or check $currentTenant->resolved().
 */
class ResolveTenant
{
    private const RESERVED_SUBDOMAINS = ['api', 'admin', 'www', 'app'];

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveFromSubdomain($request)
            ?? $this->resolveFromHeader($request)
            ?? $this->resolveFromFormField($request);

        if ($tenant !== null) {
            if ($tenant instanceof Response) {
                return $tenant;
            }
            $this->currentTenant->set($tenant);
        }

        return $next($request);
    }

    private function resolveFromSubdomain(Request $request): Tenant|Response|null
    {
        $subdomain = $this->extractSubdomain($request->getHost());

        if ($subdomain === null || in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            return null;
        }

        return $this->lookupTenant($subdomain);
    }

    private function resolveFromHeader(Request $request): Tenant|Response|null
    {
        $headerValue = $request->header('X-Tenant');
        if (! is_string($headerValue) || $headerValue === '') {
            return null;
        }

        return $this->lookupTenant($headerValue);
    }

    /**
     * Allow login/register endpoints to specify the tenant in the request body
     * (for shared-URL flows like a marketing site or mobile app). Only honored
     * on a small allow-list of public auth endpoints.
     */
    private function resolveFromFormField(Request $request): Tenant|Response|null
    {
        if (! $this->isShareableAuthEndpoint($request)) {
            return null;
        }

        $tenantSlug = $request->input('tenant');
        if (! is_string($tenantSlug) || $tenantSlug === '') {
            return null;
        }

        return $this->lookupTenant($tenantSlug);
    }

    private function isShareableAuthEndpoint(Request $request): bool
    {
        return $request->isMethod('POST') && (
            $request->is('api/v1/auth/login')
            || $request->is('api/v1/auth/register')
            || $request->is('api/v1/auth/mfa/verify')
            || $request->is('api/v1/auth/password/forgot')
            || $request->is('api/v1/auth/password/reset')
        );
    }

    private function lookupTenant(string $subdomain): Tenant|Response
    {
        // Cache tenant for 5 minutes to avoid a DB hit on every request
        $tenant = Cache::remember("tenant:{$subdomain}", 300, fn () => Tenant::where('subdomain', $subdomain)->first());

        if (! $tenant) {
            return response()->json([
                'type' => 'https://ethr.et/errors/tenant-not-found',
                'title' => 'Tenant Not Found',
                'status' => 404,
                'detail' => "No tenant found for '{$subdomain}'.",
            ], 404)->withHeaders(['Content-Type' => 'application/problem+json']);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/tenant-inactive',
                'title' => 'Tenant Inactive',
                'status' => 403,
                'detail' => 'This tenant account is not active.',
            ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
        }

        return $tenant;
    }

    private function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            return $parts[0];
        }

        if (count($parts) === 2 && ! in_array($parts[1], ['test', 'localhost'], true)) {
            return $parts[0];
        }

        return null;
    }
}
