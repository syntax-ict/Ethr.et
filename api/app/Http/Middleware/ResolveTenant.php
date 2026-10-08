<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Support\TenancyDomain;
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
 *   0. Custom domain — a host the platform admin assigned to one tenant
 *      (`hr.acme.com`), once verified. Checked first: on that host it is the
 *      only answer.
 *   1. Subdomain — acme.ethr.et, wherever the host can serve it.
 *   2. X-Tenant header — on the APEX (ethr.et / www.ethr.et), in every
 *      environment since 2026-10-06, because the production host cannot serve
 *      wildcard subdomains (M3) and ethr.et/{slug} is how an organisation is
 *      reached instead. Ignored on a tenant subdomain, a custom domain, the
 *      platform host and any host this deployment does not own. See
 *      resolveFromHeader() for why that is safe.
 *   3. Tenant slug in form body — login/register/password endpoints only, so a
 *      user arriving at the apex can say which organisation they belong to.
 *      Safe in production: it selects *which* tenant to authenticate against,
 *      it does not grant access — credentials and EnsureUserBelongsToTenant
 *      still decide that.
 *
 * If none of the above identify a tenant, the request proceeds without a
 * tenant context. Routes that need a tenant must rely on the global scope
 * (whereRaw('0 = 1')) returning no results, or check $currentTenant->resolved().
 *
 * One host is exempt from all three: the platform host resolves no tenant by
 * any means — see isPlatformHost().
 */
class ResolveTenant
{
    // Reserved names live on the Tenant model so resolution, availability
    // checks and registration cannot drift apart — see Tenant::RESERVED_SUBDOMAINS.

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPlatformHost($request)) {
            return $next($request);
        }

        $tenant = $this->resolveFromCustomDomain($request)
            ?? $this->resolveFromSubdomain($request)
            ?? $this->resolveFromHeader($request)
            ?? $this->resolveFromFormField($request);

        if ($tenant !== null) {
            if ($tenant instanceof Response) {
                if ($this->isTenantCreationEndpoint($request)) {
                    return $next($request);
                }

                return $tenant;
            }
            $this->currentTenant->set($tenant);
        }

        return $next($request);
    }

    /**
     * The platform host carries no tenant context — by any selector, ever.
     *
     * `admin` being reserved already stopped the *subdomain* resolving to a
     * tenant, which read as sufficient: EnsurePlatformContext asks only whether
     * a tenant is resolved, and on admin.ethr.et none was. But resolution then
     * fell through to the next selector, and the form-field fallback is honoured
     * on the login endpoints — so `POST admin.ethr.et/api/v1/auth/login` with
     * `tenant: habru` in the body resolved Habru and authenticated a Habru user
     * on the platform hostname. No data crossed a boundary (the cookie is
     * host-only and the console still demands super_admin), but tenant
     * authentication was reachable on the platform host, which is precisely the
     * separation this split exists to make structural.
     *
     * Deliberately keyed to the platform host alone rather than to every
     * reserved subdomain: `www` is an apex alias in most deployments, and
     * refusing the fallback there would break login for anyone who reaches the
     * public site by its conventional name.
     */
    private function isPlatformHost(Request $request): bool
    {
        return $this->extractSubdomain($request->getHost()) === Tenant::PLATFORM_SUBDOMAIN;
    }

    /**
     * A host the platform admin assigned to one tenant, and that tenant proved
     * it controls (CustomDomainVerifier). A pending domain resolves nothing:
     * until 2026-10-08 assignment alone made a domain resolve, with nothing to
     * show the organisation held it or that it pointed here.
     *
     * Only consulted when APP_DOMAIN is set: without it there is no way to tell
     * a custom domain from the deployment's own single host. A resolved custom
     * domain is also added to Sanctum's first-party list for this request:
     * `SANCTUM_STATEFUL_DOMAINS` is static configuration and cannot name
     * domains assigned at runtime. Without this, sign-in on hr.acme.com would
     * answer 200 and the next request 401, because Sanctum would not treat the
     * session cookie as first-party. This middleware runs before
     * EnsureFrontendRequestsAreStateful (bootstrap/app.php prepends the api
     * group), which is what makes the addition count.
     */
    private function resolveFromCustomDomain(Request $request): Tenant|Response|null
    {
        $root = TenancyDomain::root();
        if ($root === null) {
            return null;
        }

        $host = Tenant::normaliseDomain($request->getHost());
        if ($host === null || $host === $root || str_ends_with($host, '.'.$root)) {
            return null;
        }

        $tenant = Cache::remember(
            "tenant-domain:{$host}",
            300,
            fn () => Tenant::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->first() ?? false,
        );

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $stateful = (array) config('sanctum.stateful', []);
        if (! in_array($request->getHttpHost(), $stateful, true)) {
            config(['sanctum.stateful' => array_values(array_unique([...$stateful, $host, $request->getHttpHost()]))]);
        }

        return $this->lookupTenant($tenant->subdomain);
    }

    /**
     * A host this deployment owns that names no tenant: the apex, its `www`
     * alias, or any host at all on a single-host install with no APP_DOMAIN.
     */
    private function isSharedHost(Request $request): bool
    {
        $root = TenancyDomain::root();
        if ($root === null) {
            return true;
        }

        $host = Tenant::normaliseDomain($request->getHost());

        return $host === $root || $host === 'www.'.$root;
    }

    private function resolveFromSubdomain(Request $request): Tenant|Response|null
    {
        $subdomain = $this->extractSubdomain($request->getHost());

        if ($subdomain === null || in_array($subdomain, Tenant::RESERVED_SUBDOMAINS, true)) {
            return null;
        }

        return $this->lookupTenant($subdomain);
    }

    /**
     * The tenant named by X-Tenant, honoured where no host can name one.
     *
     * Until 2026-10-06 this was refused outside local/testing, on the reasoning
     * that the client should not choose its tenant. Two facts changed that.
     * The host is client-chosen too: anyone can type acme.ethr.et, so the
     * subdomain was never a secret. What actually stops a leaked token reading
     * another tenant is EnsureUserBelongsToTenant, which compares the resolved
     * tenant with the authenticated user's own, and it applies to the header
     * exactly as to the host. And without wildcard subdomains (M3) the apex is
     * the only host there is: refusing the header there meant every organisation
     * could sign in and then saw empty pages, because nothing resolved its tenant.
     *
     * So it is honoured where the deployment owns the host but the host names
     * no tenant: the apex, its `www` alias, and single-host installs with no
     * APP_DOMAIN. Never on a tenant subdomain or custom domain (the host already
     * answered, and those resolve before this runs), never on the platform host
     * (handled before any selector), and never on a host this deployment does
     * not own. A stranger pointing DNS at the account gets no tenant selector.
     */
    private function resolveFromHeader(Request $request): Tenant|Response|null
    {
        if (! app()->environment('local', 'testing') && ! $this->isSharedHost($request)) {
            return null;
        }

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

    private function isTenantCreationEndpoint(Request $request): bool
    {
        return ($request->isMethod('POST') && $request->is('api/v1/auth/register'))
            || $request->is('api/v1/register/check-subdomain');
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

    /**
     * The tenant label in a host, or null when the host names no tenant.
     *
     * `APP_DOMAIN` is what makes this correct rather than merely usually right.
     * Counting labels cannot distinguish an apex from a subdomain: `ethr.et` is
     * two labels and so is `acme.com`, and a deployment on `ethr.co.uk` would
     * read its own apex as the tenant "ethr". That was not hypothetical — before
     * APP_DOMAIN existed, `ethr.et` resolved to a tenant named "ethr", so every
     * API request to the public apex answered 404 "No tenant found for 'ethr'".
     *
     * With APP_DOMAIN set, the rule is exact: the apex is not a tenant, one
     * label in front of the apex is, and anything outside the domain is refused.
     * Without it (local development, tests) fall back to label counting — but
     * treat a two-label host as an apex, which is what a registrable domain is.
     */
    private function extractSubdomain(string $host): ?string
    {
        $appDomain = TenancyDomain::root();

        if ($appDomain !== null) {
            if (strcasecmp($host, $appDomain) === 0) {
                return null;
            }

            $suffix = '.'.$appDomain;
            if (! str_ends_with(strtolower($host), strtolower($suffix))) {
                return null;
            }

            $label = substr($host, 0, -strlen($suffix));

            // Only a single label in front of the apex is a tenant. `a.b.ethr.et`
            // is neither routed nor covered by the wildcard certificate, so it
            // must not quietly resolve to "a".
            return str_contains($label, '.') || $label === '' ? null : $label;
        }

        $parts = explode('.', $host);

        return count($parts) >= 3 ? $parts[0] : null;
    }
}
