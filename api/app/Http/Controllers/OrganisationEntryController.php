<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\FrontendUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * ethr.et/{slug}: the fallback address of every organisation, which only
 * redirects.
 *
 * An organisation has one canonical address (owner decision 2026-10-08):
 * its verified custom domain, else its subdomain when this deployment serves
 * them, else the shared host. This sends a real organisation to the first of
 * those it has (FrontendUrl::canonicalOrigin), at its sign-in page. Only an
 * organisation with neither lands on `/login?org={slug}`, where the frontend
 * prefills it and names it in X-Tenant after sign-in.
 *
 * An unknown or reserved name gets a real 404: the static site's own 404
 * page, with a 404 status, not a soft one.
 *
 * Only single-segment paths with no file behind them reach this; .htaccess
 * serves every real page and asset before it. The answer is the same for an
 * inactive organisation as for an active one: the sign-in page explains the
 * state, and the entry URL does not reveal it.
 */
final class OrganisationEntryController
{
    public function __invoke(string $slug): RedirectResponse|Response
    {
        $slug = strtolower($slug);

        $tenant = in_array($slug, Tenant::RESERVED_SUBDOMAINS, true)
            ? null
            : Tenant::query()->where('subdomain', $slug)->first();

        if ($tenant === null) {
            return $this->notFound();
        }

        $origin = FrontendUrl::canonicalOrigin($tenant);

        return redirect($origin !== null ? $origin.'/login' : '/login?org='.rawurlencode($slug));
    }

    private function notFound(): Response
    {
        $page = public_path('404.html');

        return response(is_file($page) ? (string) file_get_contents($page) : 'Not Found', 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
