<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use Illuminate\Http\Response;

/**
 * The exported sign-in page served as one organisation's portal: the same
 * file for everyone, with that organisation's head (PortalHead) put in
 * before it is sent. Two addresses serve it, the shared ethr.et/{slug}
 * (OrganisationEntryController) and `/login` on the organisation's own host
 * (OrganisationLoginPageController), and both answer through here so they
 * cannot drift.
 */
final class PortalPage
{
    /** As many JobPosting records as the feed lists vacancies. */
    private const JOB_POSTINGS = 6;

    public static function respond(Tenant $tenant, string $file): Response
    {
        $landing = TenantLanding::present($tenant);

        // The organisation's own head: what a search result or a shared link
        // shows, and the structured data behind it. Only the public vacancies
        // the feed lists get a JobPosting; the tenant is set for that query
        // and released after, as a queued job would.
        $currentTenant = app(CurrentTenant::class);
        $currentTenant->set($tenant);
        try {
            $vacancies = $landing['sections']['vacancies']
                ? Vacancy::query()->publiclyListed()->orderByDesc('published_at')->limit(self::JOB_POSTINGS)->get()->all()
                : [];
            $head = PortalHead::render($tenant, $vacancies);
            $ownIcon = $tenant->brandAssetUrl('mark') !== null;
        } finally {
            $currentTenant->forget();
        }
        $html = PortalHead::inject((string) file_get_contents($file), $head, $ownIcon);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // The page is the same for every organisation and every release
            // changes it; the branding comes from the API, not this response.
            ->header('Cache-Control', 'no-cache')
            // Out of search results unless the organisation switched listing
            // on in its portal settings (default off): listing is its choice.
            ->header('X-Robots-Tag', $landing['listed'] ? 'index, follow' : 'noindex, nofollow');
    }
}
