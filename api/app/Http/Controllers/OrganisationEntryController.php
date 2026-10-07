<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * ethr.et/{slug}: an organisation's own entry URL.
 *
 * The production host cannot serve wildcard subdomains (M3), so an
 * organisation is reached by path instead. This sends a real organisation to
 * its sign-in page with the organisation preset (`/login?org={slug}`), where
 * the frontend remembers it and names it in X-Tenant from then on. An unknown
 * or reserved name gets a real 404: the static site's own 404 page, with a 404
 * status, not a soft one.
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

        if (in_array($slug, Tenant::RESERVED_SUBDOMAINS, true)
            || ! Tenant::query()->where('subdomain', $slug)->exists()) {
            return $this->notFound();
        }

        return redirect('/login?org='.rawurlencode($slug));
    }

    private function notFound(): Response
    {
        $page = public_path('404.html');

        return response(is_file($page) ? (string) file_get_contents($page) : 'Not Found', 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
