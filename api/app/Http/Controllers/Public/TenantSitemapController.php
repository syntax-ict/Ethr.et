<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Public\PublishedTenantLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `sitemap.xml` for a tenant host.
 *
 * Deliberately the smallest sitemap that is still worth serving: this tenant's
 * own page and its two language variants. It is **not** an index of tenants,
 * and must never become one — a platform-wide list of which organisations use
 * ETHR is an unauthorised-discovery surface wearing an SEO hat, which is why
 * the design document refuses it by name.
 *
 * A tenant that has asked not to be indexed gets a 404 rather than an empty
 * sitemap. An empty one is still a statement that the host exists and serves a
 * page, and `is_indexable` means "do not put me in search engines", not "put an
 * empty document in them".
 */
class TenantSitemapController extends Controller
{
    public function __construct(private readonly PublishedTenantLocator $locator) {}

    public function __invoke(Request $request): Response
    {
        // Same locator as the landing page and robots.txt, so all three agree
        // about which hosts have a public page.
        [, $profile] = $this->locator->locate();

        if (! $profile->is_indexable || ! app()->environment('production')) {
            throw new NotFoundHttpException;
        }

        $base = $request->getSchemeAndHttpHost();
        $lastmod = ($profile->updated_at ?? $profile->published_at)?->toAtomString();

        $xml = $this->render($base, $lastmod);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * The document itself.
     *
     * Built with a heredoc rather than a Blade view on purpose: an XML document
     * assembled by a templating engine that escapes for HTML is how a stray
     * entity ends up making the sitemap unparseable. The only interpolated
     * values here are a URL this application generated and a timestamp it
     * formatted, neither of which is tenant-controlled.
     */
    private function render(string $base, ?string $lastmod): string
    {
        $lastmodTag = $lastmod === null ? '' : "\n        <lastmod>{$lastmod}</lastmod>";

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
                    xmlns:xhtml="http://www.w3.org/1999/xhtml">
                <url>
                    <loc>{$base}/</loc>{$lastmodTag}
                    <xhtml:link rel="alternate" hreflang="en" href="{$base}/?lang=en"/>
                    <xhtml:link rel="alternate" hreflang="am" href="{$base}/?lang=am"/>
                    <xhtml:link rel="alternate" hreflang="x-default" href="{$base}/"/>
                    <changefreq>monthly</changefreq>
                </url>
            </urlset>
            XML;
    }
}
