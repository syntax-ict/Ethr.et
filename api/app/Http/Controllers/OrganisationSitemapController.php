<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use App\Support\PortalHead;
use App\Support\TenantLanding;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Response;

/**
 * The sitemap of organisation portals, named beside the site's own in
 * robots.txt: one entry per organisation that is in use and switched
 * listing on, at its canonical sign-in address, with its English and
 * Amharic alternates. An organisation that did not opt in is absent, as its
 * page is `noindex`; nothing about any organisation but its address leaves.
 *
 * `Tenant` is a global model (no tenant scope to bypass): this is the one
 * place that lists across organisations, and only those that asked to be.
 */
class OrganisationSitemapController extends Controller
{
    /** The newest public articles listed per organisation. */
    private const ARTICLES = 50;

    #[ExcludeRouteFromDocs]
    public function __invoke(): Response
    {
        $entries = Tenant::query()
            ->operational()
            ->where('landing->listed', true)
            ->orderBy('subdomain')
            ->get()
            ->map(fn (Tenant $tenant): string => implode("\n", array_map(
                self::entry(...),
                [FrontendUrl::at($tenant, 'login'), ...self::news($tenant)],
            )))
            ->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            .$entries.($entries === '' ? '' : "\n")
            .'</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The organisation's news page and its newest public articles, when it
     * publishes news and has its own host. On the shared host the news pages
     * are served as exported, with no head of their own and `noindex`, so
     * listing them would only point a crawler at pages that ask not to be
     * kept. The articles are read through the tenant scope, set for this
     * organisation and released after.
     *
     * @return list<string>
     */
    private static function news(Tenant $tenant): array
    {
        if (! TenantLanding::present($tenant)['sections']['announcements']
            || FrontendUrl::canonicalOrigin($tenant) === null) {
            return [];
        }

        $currentTenant = app(CurrentTenant::class);
        $currentTenant->set($tenant);
        try {
            $ids = Announcement::query()
                ->publiclyListed()
                ->orderByDesc('published_at')
                ->limit(self::ARTICLES)
                ->pluck('public_id')
                ->all();
        } finally {
            $currentTenant->forget();
        }

        return [
            PortalHead::newsUrl($tenant),
            ...array_map(fn ($id): string => PortalHead::newsUrl($tenant, (string) $id), $ids),
        ];
    }

    private static function entry(string $address): string
    {
        $url = htmlspecialchars($address, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $joiner = str_contains($url, '?') ? '&amp;' : '?';

        return '  <url>'
            ."<loc>{$url}</loc>"
            ."<xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$url}{$joiner}lang=en\"/>"
            ."<xhtml:link rel=\"alternate\" hreflang=\"am\" href=\"{$url}{$joiner}lang=am\"/>"
            ."<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$url}\"/>"
            .'</url>';
    }
}
