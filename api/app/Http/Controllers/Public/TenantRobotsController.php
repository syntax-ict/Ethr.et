<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Public\PublishedTenantLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `robots.txt` for a tenant host.
 *
 * A tenant host had none at all, which means a crawler asking for it fell
 * through to whatever the frontend served — and the tenant's own `is_indexable`
 * choice was expressed only as a meta tag and a header, both of which require
 * fetching the page first.
 *
 * Answers 404 in exactly the cases the landing page does, and for the same
 * reason: a robots.txt that exists on `acme.ethr.et` and not on
 * `notacustomer.ethr.et` enumerates ETHR's customers one hostname at a time.
 * The publication check therefore comes before the indexability question.
 */
class TenantRobotsController extends Controller
{
    public function __construct(private readonly PublishedTenantLocator $locator) {}

    public function __invoke(Request $request): Response
    {
        // Same locator as the landing page, so this 404s in exactly the cases
        // that page does — see PublishedTenantLocator for why that matters.
        [, $profile] = $this->locator->locate();

        $base = $request->getSchemeAndHttpHost();

        // Three separate reasons to refuse indexing, all of which have to win:
        // the tenant's own choice, and any non-production deployment answering
        // for tenant hostnames — a staging copy of a customer's page in search
        // results is the tenant's problem but our fault.
        $allowed = $profile->is_indexable && app()->environment('production');

        $body = $allowed
            ? "User-agent: *\nAllow: /\nDisallow: /media/\nDisallow: /preview/\n\nSitemap: {$base}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // Same reasoning as the landing page: one path, a different
            // document per Host, so a path-keyed shared cache would hand one
            // tenant's robots.txt to another tenant's crawler.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
