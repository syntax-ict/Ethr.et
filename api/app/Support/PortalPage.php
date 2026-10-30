<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use Illuminate\Http\Response;

/**
 * The exported portal pages served as one organisation's: the same file for
 * everyone, with that organisation's head (PortalHead) put in before it is
 * sent. The sign-in page is served at the shared ethr.et/{slug}
 * (OrganisationEntryController) and at `/login` on the organisation's own
 * host (OrganisationLoginPageController); its news page and articles at
 * `/news` and `/news/{id}` there (NewsPageController). All of them answer
 * through here so their headers cannot drift.
 */
final class PortalPage
{
    /** As many JobPosting records as the feed lists vacancies. */
    private const JOB_POSTINGS = 6;

    /** The sign-in portal. */
    public static function respond(Tenant $tenant, string $file): Response
    {
        // The organisation's own head: what a search result or a shared link
        // shows, and the structured data behind it. Only the public vacancies
        // the feed lists get a JobPosting; the tenant is set for that query
        // and released after, as a queued job would.
        [$head, $ownIcon] = self::withTenant($tenant, function () use ($tenant): array {
            $vacancies = TenantLanding::publicSections($tenant)['vacancies']
                ? Vacancy::query()->publiclyListed()->orderByDesc('published_at')->limit(self::JOB_POSTINGS)->get()->all()
                : [];

            return [PortalHead::render($tenant, $vacancies), $tenant->brandAssetUrl('mark') !== null];
        });

        return self::send($file, $head, $ownIcon, TenantLanding::indexable($tenant), 200);
    }

    /**
     * 503 for a section the platform switched off, so a search engine keeps
     * the page and tries later and the page says "temporarily unavailable"
     * (owner decision D7); 404 for one the organisation does not show.
     */
    private static function missingStatus(Tenant $tenant, string $section): int
    {
        return in_array($section, TenantLanding::paused($tenant), true) ? 503 : 404;
    }

    /**
     * The organisation's news page. With its news section off there is no
     * such page: the file is still sent, so the visitor sees the page's own
     * "no news here" state and a way home, but as a 404 that no search engine
     * keeps.
     */
    public static function news(Tenant $tenant, string $file): Response
    {
        if (! TenantLanding::publicSections($tenant)['announcements']) {
            return self::plain($file, self::missingStatus($tenant, 'announcements'));
        }

        $head = PortalHead::news($tenant);

        return self::send($file, $head, $tenant->brandAssetUrl('mark') !== null, TenantLanding::indexable($tenant), 200);
    }

    /**
     * One article. Only an announcement the news page itself lists has one:
     * published, not expired, marked for the public, for the whole
     * organisation, with the news section on. Anything else, a draft
     * included, is the same 404 with the page's own "not found" state.
     */
    public static function article(Tenant $tenant, string $file, string $publicId): Response
    {
        if (! TenantLanding::publicSections($tenant)['announcements']) {
            return self::plain($file, self::missingStatus($tenant, 'announcements'));
        }

        $head = self::withTenant($tenant, function () use ($tenant, $publicId): ?string {
            $article = Announcement::query()->publiclyListed()->where('public_id', $publicId)->first();

            return $article === null ? null : PortalHead::article($tenant, $article);
        });
        if ($head === null) {
            return self::plain($file, 404);
        }

        return self::send($file, $head, $tenant->brandAssetUrl('mark') !== null, TenantLanding::indexable($tenant), 200);
    }

    /**
     * The file as exported, kept out of search results: for a host that
     * names no organisation, and for a page the organisation does not have.
     */
    public static function plain(string $file, int $status = 200): Response
    {
        return response((string) file_get_contents($file), $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function send(string $file, string $head, bool $ownIcon, bool $indexable, int $status): Response
    {
        $html = PortalHead::inject((string) file_get_contents($file), $head, $ownIcon);

        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // The page is the same for every organisation and every release
            // changes it; the content comes from the API, not this response.
            ->header('Cache-Control', 'no-cache')
            // In search results while the organisation is in use, unless it
            // switched listing off in its portal settings (default on).
            ->header('X-Robots-Tag', $indexable ? 'index, follow' : 'noindex, nofollow');
    }

    /**
     * Runs tenant-scoped reads for one organisation outside any request
     * tenant, and releases it after, as a queued job would.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private static function withTenant(Tenant $tenant, callable $read): mixed
    {
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->set($tenant);
        try {
            return $read();
        } finally {
            $currentTenant->forget();
        }
    }
}
