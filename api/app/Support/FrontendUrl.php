<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TenantAddressMode;
use App\Models\Tenant;
use App\Models\User;

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
     * null when it lives on the shared host.
     *
     * Decided by the organisation's own choice (Tenant::effectiveAddressMode,
     * owner decision 2026-10-11), which falls back the way the old fixed
     * tiering did when the choice cannot be honoured yet:
     *
     *   - custom domain: https://hr.acme.com, once VERIFIED. A pending domain
     *     is not an address: it may not point here yet, and nothing proves the
     *     organisation holds it.
     *   - subdomain: https://acme.ethr.et, where this deployment serves them
     *     (TENANCY_SUBDOMAINS=true), at the frontend's scheme and port.
     *   - path: null. The organisation is reached on the shared host, where
     *     `ethr.et/{slug}` leads to `/login?org={slug}`.
     */
    public static function canonicalOrigin(Tenant $tenant): ?string
    {
        // A single-tenant install (ETHR_MODE=single) has no per-organisation
        // origin: the one organisation lives at FRONTEND_URL itself, whatever
        // its address mode says — the row may carry SaaS-era settings an
        // on-prem host cannot serve, and honouring them would point every
        // mail link at a host that does not answer.
        if (InstallMode::single()) {
            return null;
        }

        // Both hosted tiers take the scheme and port from the frontend URL,
        // which is https in production and whatever the rehearsal serves
        // locally. A custom domain hard-coded to https pointed every redirect
        // at a port the local-production rehearsal does not listen on.
        $frontend = parse_url((string) config('app.frontend_url'));
        $scheme = $frontend['scheme'] ?? 'https';
        $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

        return match ($tenant->effectiveAddressMode()) {
            TenantAddressMode::CUSTOM_DOMAIN => "{$scheme}://{$tenant->custom_domain}{$port}",
            TenantAddressMode::SUBDOMAIN => "{$scheme}://{$tenant->subdomain}.".TenancyDomain::root().$port,
            TenantAddressMode::PATH => null,
        };
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

        return self::at($tenant, $path);
    }

    /**
     * `forTenant` for a tenant already in hand, with no lookup. Null stands
     * for "no organisation" (a platform account): the shared host as given.
     */
    public static function at(?Tenant $tenant, string $path): string
    {
        $origin = $tenant === null ? null : self::canonicalOrigin($tenant);

        if ($origin !== null) {
            return $origin.'/'.ltrim($path, '/');
        }

        if ($tenant !== null && trim($path, '/') === 'login') {
            // A single install has no entry URL: there is nothing to choose
            // between, so the sign-in page is plain /login.
            return InstallMode::single() ? self::to('login') : self::to($tenant->subdomain);
        }

        return self::to($path);
    }

    /**
     * A link in a notification, at the recipient's organisation. Notifications
     * are sent to users and, for platform accounts, to users with no tenant;
     * anything else (an anonymous route) gets the shared host.
     */
    public static function forNotifiable(object $notifiable, string $path): string
    {
        if (! $notifiable instanceof User) {
            return self::to($path);
        }

        // Not `$notifiable->tenant`: a notification is built off a queue
        // worker from a user loaded without relations, and the lazy load would
        // be a violation this application logs. Tenant carries no tenant
        // scope, so a lookup by its own key bypasses nothing.
        $tenant = $notifiable->relationLoaded('tenant')
            ? $notifiable->getRelation('tenant')
            : Tenant::query()->find($notifiable->tenant_id);

        return self::at($tenant instanceof Tenant ? $tenant : null, $path);
    }
}
