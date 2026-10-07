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
     * Where an organisation's people should land, in order of preference:
     *
     *   1. its custom domain, when the platform assigned one (hr.acme.com/login);
     *   2. its subdomain, when this deployment serves them (acme.ethr.et/login);
     *   3. otherwise the shared host. Its sign-in page is the organisation's
     *      entry URL, ethr.et/{slug}, which redirects to /login?org={slug}.
     *      Every other path stays on the shared host as given; callers that need
     *      the tenant there pass it in the query, as the reset link does.
     *
     * Until 2026-10-06 this always built (2). The production host cannot serve
     * wildcard subdomains (M3), so every activation and "find my organisation"
     * link pointed at a host that did not answer.
     */
    public static function forTenant(string $subdomain, string $path): string
    {
        $customDomain = Tenant::query()->where('subdomain', $subdomain)->value('custom_domain');
        if (is_string($customDomain) && $customDomain !== '') {
            return 'https://'.$customDomain.'/'.ltrim($path, '/');
        }

        $root = TenancyDomain::root();
        if ($root !== null && config('tenancy.subdomains')) {
            $frontend = parse_url((string) config('app.frontend_url'));
            $scheme = $frontend['scheme'] ?? 'https';
            $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

            return "{$scheme}://{$subdomain}.{$root}{$port}/".ltrim($path, '/');
        }

        if (trim($path, '/') === 'login') {
            return self::to($subdomain);
        }

        return self::to($path);
    }
}
