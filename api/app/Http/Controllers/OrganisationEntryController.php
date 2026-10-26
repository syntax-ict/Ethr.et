<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\FrontendUrl;
use App\Support\PortalPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * ethr.et/{slug}: every organisation's entry address.
 *
 * An organisation has one canonical address (owner decision 2026-10-08):
 * its verified custom domain, else its subdomain when this deployment serves
 * them, else the shared host. One with its own host is redirected to its
 * sign-in page there (FrontendUrl::canonicalOrigin).
 *
 * One that lives on the shared host gets its landing page here, at this
 * address: the shared sign-in template, drawn in the organisation's logo,
 * name and colours (2026-10-13). It is the exported sign-in page itself,
 * served for this path - the page reads the organisation from the URL and
 * asks /auth/tenant-context for its branding - so there is one design and
 * one authentication flow for every organisation. Where no exported page
 * exists (`next dev`), it falls back to `/login?org={slug}` as before.
 *
 * An unknown or reserved name gets a real 404: the static site's own 404
 * page, with a 404 status, not a soft one. A name the organisation used to
 * have is redirected to the address it has now.
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
            : Tenant::findBySlug($slug);

        if ($tenant === null) {
            return $this->notFound();
        }

        $origin = FrontendUrl::canonicalOrigin($tenant);

        // A name the organisation retired (a rename, 2026-10-26) forwards to
        // its address now. A 302, not a 301: the organisation may take the
        // name back, and a browser that cached a permanent redirect would
        // then bounce between the two.
        if ($tenant->subdomain !== $slug && $origin === null) {
            return redirect(FrontendUrl::to($tenant->subdomain));
        }
        if ($origin !== null) {
            return redirect($origin.'/login');
        }

        $landing = public_path('login.html');
        if (! is_file($landing)) {
            return redirect('/login?org='.rawurlencode($slug));
        }

        // The organisation's portal: the exported page with its own head
        // (PortalPage), robots by its listing switch.
        return PortalPage::respond($tenant, $landing);
    }

    private function notFound(): Response
    {
        $page = public_path('404.html');

        return response(is_file($page) ? (string) file_get_contents($page) : 'Not Found', 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
