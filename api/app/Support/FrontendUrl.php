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
}
