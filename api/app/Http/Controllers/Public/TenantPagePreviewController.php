<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\PublicPagePreset;
use App\Enums\PublicSectionKind;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\Public\PresetResolver;
use App\Services\Public\PublishedTenantLocator;
use App\Support\PublicSection;
use App\Support\PublicTenantPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant's page as it would look, before anyone else can see it.
 *
 * A builder without preview is unusable: the only way to check a change would
 * be to publish it to the public internet and look. For a page carrying an
 * organisation's name that is not an acceptable workflow.
 *
 * **Why a signed URL rather than `auth` middleware.** The dashboard is a
 * Next.js application on a different origin using Sanctum cookies. Hanging
 * session authentication off a Blade route on the tenant host would drag
 * session state into `routes/public.php`, whose entire design property is that
 * it has none — no `auth`, no `statefulApi`, nothing that could leak into the
 * anonymous surface beside it. A signature is self-contained: it authorises
 * this one URL, for fifteen minutes, and carries no session.
 *
 * The signature is the authority, which puts this in the same family as the
 * pre-authentication lookups the codebase already sanctions. It still does not
 * choose the tenant: `ResolveTenant` does that from the hostname, exactly as on
 * the public page, so a signature minted for one tenant is worthless on another
 * tenant's host.
 */
class TenantPagePreviewController extends Controller
{
    public function __construct(
        private readonly PublishedTenantLocator $locator,
        private readonly PresetResolver $presets,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Published or not — that is the point of a preview. The locator's
        // other guards still apply: a non-tenant host and an inactive tenant
        // are refused, so preview cannot reach anything the page could not.
        $tenant = $this->locator->tenant();

        $profile = TenantPublicProfile::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($profile === null) {
            // Nothing has ever been saved, so there is nothing to preview.
            abort(404);
        }

        $preset = $profile->preset === null
            ? $this->presets->derive($tenant)
            : PublicPagePreset::from($profile->preset);

        $canonicalUrl = $request->getSchemeAndHttpHost().'/';

        $page = PublicTenantPage::from($tenant, $profile, $preset, $this->sectionsFor($tenant));

        $response = response()->view('public.tenant.landing-v2', [
            'page' => $page,
            'canonicalUrl' => $canonicalUrl,
            // Never indexable, whatever the tenant chose. A preview URL that
            // reached a search engine would publish exactly the draft the
            // administrator was still deciding about.
            'indexable' => false,
            'themeStyle' => $page->themeStyleAttribute(),
            'jsonLd' => $page->jsonLd($canonicalUrl),
            'jsonLdFlags' => PublicTenantPage::JSON_LD_FLAGS,
            'bodyClass' => $page->preset->bodyClass().' is-preview',
            'alternates' => [],
            'heroKind' => PublicSectionKind::HERO,
            'isPreview' => true,
        ]);

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Every section, including the hidden ones.
     *
     * The difference from the public page, and the reason preview is worth
     * having: an administrator filling in a seeded block needs to see it before
     * revealing it. The template marks hidden ones so the preview cannot be
     * mistaken for what visitors see.
     *
     * @return list<PublicSection>
     */
    private function sectionsFor(Tenant $tenant): array
    {
        $locale = app()->getLocale();

        return TenantPublicSection::query()
            ->with('items')
            ->where('tenant_id', $tenant->id)
            ->orderBy('position')
            ->get()
            ->map(fn (TenantPublicSection $section) => PublicSection::from($tenant, $section, $locale))
            ->unique(fn (PublicSection $section) => $section->kind->readsProfile()
                ? $section->kind->value
                : spl_object_id($section))
            ->values()
            ->all();
    }
}
