<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Frontend URLs ETHR hands out: in mail, in redirects, in anything a person clicks.
 */
final class FrontendUrl
{
    public static function to(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * The one address an organisation lives at, as an origin with no path, or
     * null when it has none of its own and lives on the shared host.
     *
     * Three tiers, in order (owner decision 2026-10-08):
     *
     *   1. Enterprise: its custom domain, once VERIFIED (https://hr.acme.com).
     *      A pending domain is not an address: it may not point here yet, and
     *      nothing proves the organisation holds it.
     *   2. Standard: its subdomain, when this deployment serves them
     *      (TENANCY_SUBDOMAINS=true), at the frontend's scheme and port.
     *   3. Fallback: null. The organisation is reached on the shared host, where
     *      `ethr.et/{slug}` leads to `/login?org={slug}`.
     */
    public static function canonicalOrigin(Tenant $tenant): ?string
    {
        // Both tiers take the scheme and port from the frontend URL, which is
        // https in production and whatever the rehearsal serves locally. A
        // custom domain hard-coded to https pointed every redirect at a port
        // the local-production rehearsal does not listen on, which made the
        // Enterprise tier the one that could not be exercised before go-live.
        $frontend = parse_url((string) config('app.frontend_url'));
        $scheme = $frontend['scheme'] ?? 'https';
        $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

        if ($tenant->hasVerifiedCustomDomain()) {
            return "{$scheme}://{$tenant->custom_domain}{$port}";
        }

        $root = TenancyDomain::root();
        if ($root !== null && config('tenancy.subdomains')) {
            return "{$scheme}://{$tenant->subdomain}.{$root}{$port}";
        }

        return null;
    }

    /**
     * Where an organisation's people should land: `$path` at its canonical
     * origin, or on the shared host when it has none. There, the sign-in page
     * is the organisation's entry URL, ethr.et/{slug}, which redirects to
     * /login?org={slug}; every other path stays on the shared host as given,
     * and callers that need the tenant there pass it in the query, as the reset
     * link does.
     *
     * Until 2026-10-06 this always built a subdomain link. The production host
     * cannot serve wildcard subdomains (M3), so every activation and "find my
     * organisation" link pointed at a host that did not answer.
     */
    public static function forTenant(string $subdomain, string $path): string
    {
        // A slug with no row (yet) still gets its subdomain when those are
        // served, as it always did; it simply has no custom domain.
        $tenant = Tenant::query()->where('subdomain', $subdomain)->first()
            ?? new Tenant(['subdomain' => $subdomain]);
        $origin = self::canonicalOrigin($tenant);

        if ($origin !== null) {
            return $origin.'/'.ltrim($path, '/');
        }

        if (trim($path, '/') === 'login') {
            return self::to($subdomain);
        }

        return self::to($path);
    }
}
