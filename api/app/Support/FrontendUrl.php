<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A link into the web app, for anything that leaves the API — chiefly email.
 *
 * `url('/approvals')` is the API's own host: `APP_URL`, or whatever request is
 * current, which in a queue worker is none at all. The pages live on the
 * frontend, so eight notification emails sent people to an API path that
 * serves no page. `config('app.frontend_url')` is where the reset and
 * activation emails already pointed, and it falls back to `APP_URL` itself
 * when the two share a host (see `config/app.php`).
 */
final class FrontendUrl
{
    public static function to(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * A link into one organisation's own app: `https://acme.ethr.et/login`.
     *
     * Where the hostname picks the tenant (`APP_DOMAIN` set — production), the
     * page belongs on the tenant's subdomain: that is where its session lives,
     * so a person landing on the apex would have to find their organisation's
     * address before they could sign in. The scheme and any port come from
     * `app.frontend_url`, so a local `http://localhost:3000` stays plain http.
     *
     * Single-host (no `APP_DOMAIN` — local development, tests) has no
     * subdomains to link to, so this is `to()`; a caller that needs the tenant
     * named on that host carries it in the query string, as the reset and
     * activation links already do.
     */
    public static function forTenant(string $subdomain, string $path): string
    {
        $root = TenancyDomain::root();
        if ($root === null) {
            return self::to($path);
        }

        $frontend = parse_url((string) config('app.frontend_url'));
        $scheme = $frontend['scheme'] ?? 'https';
        $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

        return "{$scheme}://{$subdomain}.{$root}{$port}/".ltrim($path, '/');
    }
}
