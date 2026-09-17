<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\PublicPagePreset;
use App\Enums\PublicSectionKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PublicSecurityHeaders;
use App\Models\Tenant;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use App\Services\Public\PresetResolver;
use App\Services\Public\PublishedTenantLocator;
use App\Support\PublicSection;
use App\Support\PublicTenantPage;
use App\Support\TenancyDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant's public landing page.
 *
 * Adds no tenant-resolution logic of its own — ResolveTenant has already run
 * and either resolved the host's tenant or answered for it. What this adds is
 * the visibility decision, and one property of it is worth stating because it
 * is easy to get backwards: an unpublished tenant answers **404, not 403**.
 *
 * 403 would be the honest status — the resource exists and you may not see it —
 * and that honesty is the problem. A 403 on `acme.ethr.et` and a 404 on
 * `notacustomer.ethr.et` tells an anonymous visitor which organisations use
 * ETHR, one guess at a time. Whether a tenant has chosen to publish is that
 * tenant's business, so both cases look identical from outside.
 */
class TenantLandingController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly PresetResolver $presets,
        private readonly PublishedTenantLocator $locator,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Registering `/` here overrides whatever `routes/web.php` had for it,
        // because Laravel's route collection is keyed by method and URI and the
        // last registration wins. So this controller has to answer for every
        // host, not just tenant ones — and on a non-tenant host it must answer
        // exactly as before, or a request to the apex starts 404ing.
        if (! $this->isTenantHost()) {
            // Exempt from the tenant page's CSP: this is the stock `welcome`
            // view, which loads a webfont and inline styles and is not a page
            // this feature owns. Found in a browser — the policy rendered it
            // unstyled and filled the console, for no security gain, on a page
            // production never serves (nginx sends the apex to Next.js).
            return PublicSecurityHeaders::exempt(response()->view('welcome'));
        }

        [$tenant, $profile] = $this->locator->locate();

        // A null `preset` column means this tenant has not opted into the new
        // layout, and it is the default for every row that existed before the
        // feature shipped. So deploying changes no live page: an administrator
        // moves their own page, when they choose to, and can move it back.
        $classic = $profile->preset === null;

        // A classic page keeps GENERAL rather than its derived preset, and the
        // reason is easy to miss: the preset also chooses the schema.org type.
        // Resolving it here would silently change a never-opted-in hospital's
        // structured data from Organization to Hospital — invisible to a
        // visitor, visible to every crawler, and a change to a live page that
        // nobody asked for. "Unchanged" has to mean unchanged.
        $preset = $classic
            ? PublicPagePreset::GENERAL
            : $this->presets->resolve($tenant, $profile);

        $page = PublicTenantPage::from(
            $tenant,
            $profile,
            $preset,
            // The classic layout renders no sections, so it does not pay for
            // the query either.
            $classic ? [] : $this->sectionsFor($tenant),
        );

        $canonicalUrl = $request->getSchemeAndHttpHost().'/';

        $response = response()->view($classic ? 'public.tenant.landing' : 'public.tenant.landing-v2', [
            'page' => $page,
            'canonicalUrl' => $canonicalUrl,
            // Supplied by the controller rather than computed in the template,
            // so no public Blade file needs an `@php` block — and, for the
            // JSON-LD, so the template holds no array literal for Blade's
            // bracket matcher to choke on. See PublicTenantPage::jsonLd().
            'indexable' => $page->isIndexable && app()->environment('production'),
            'themeStyle' => $page->themeStyleAttribute(),
            'jsonLd' => $page->jsonLd($canonicalUrl),
            'jsonLdFlags' => PublicTenantPage::JSON_LD_FLAGS,
            // Both omitted entirely on the classic layout. A preset skin would
            // change the appearance of a page that opted out of presets, and
            // the hreflang block would change its markup — both are edits to a
            // live page nobody asked for, which is the thing the opt-in exists
            // to prevent. They arrive together when a tenant opts in.
            ...($classic ? [] : [
                'bodyClass' => $page->preset->bodyClass(),
                'alternates' => self::alternatesFor($canonicalUrl),
                // The hero is the one kind the template special-cases, so it
                // needs the enum case to compare against. Supplied here
                // because a public Blade file may not open an @php block —
                // the rule that keeps an unescaped echo from ever appearing
                // in one.
                'heroKind' => PublicSectionKind::HERO,
            ]),
        ]);

        // `private, no-store` rather than a public max-age, deliberately.
        //
        // This application serves a different document at the same path on
        // every tenant hostname. A shared cache that keys on path and not on
        // Host — a CDN edge, a corporate proxy, a misconfigured reverse proxy —
        // would serve one tenant's page to another tenant's visitors, which is
        // a cross-tenant leak arriving through infrastructure rather than code.
        // The page is cheap to render; the risk is not worth the milliseconds.
        // The asset route caches aggressively because an image is inherently
        // keyed by its own URL.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        if (! $page->isIndexable || ! app()->environment('production')) {
            // Non-production deployments must never be indexed, whatever the
            // tenant chose: dev.ethr.et answering for tenant hostnames would
            // otherwise put duplicate, unfinished copies of a customer's page
            // into search results.
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * The visible sections for this tenant, as view-models.
     *
     * Two queries for the whole tree regardless of how many sections or items
     * there are — one for the sections, one eager-load for their items. A
     * partial that lazily touched `$section->items` would turn a twenty-block
     * page into twenty-one queries, on an anonymous route, on shared hosting;
     * PublicPageQueryBudgetTest fails if that ever happens.
     *
     * @return list<PublicSection>
     */
    private function sectionsFor(Tenant $tenant): array
    {
        $locale = app()->getLocale();

        return TenantPublicSection::query()
            ->with('items')
            ->where('tenant_id', $tenant->id)
            ->where('is_visible', true)
            ->orderBy('position')
            ->get()
            ->map(fn (TenantPublicSection $section) => PublicSection::from($tenant, $section, $locale))
            ->filter(fn (PublicSection $section): bool => $section->hasContent())
            // The kinds that read the profile appear once or not at all. Two
            // hero blocks would put two <h1> elements on the page, which
            // breaks heading navigation for anyone using a screen reader —
            // and the builder is exactly where that creeps in, because every
            // section is somebody's idea of the most important one.
            //
            // The API refuses to create a second one; this is what keeps the
            // page correct for rows that predate that rule or arrive by some
            // other route.
            ->unique(fn (PublicSection $section) => $section->kind->readsProfile()
                ? $section->kind->value
                : spl_object_id($section))
            ->values()
            ->all();
    }

    /**
     * The language variants of this page, for `hreflang`.
     *
     * `x-default` is the unsuffixed URL, which serves whichever locale the
     * tenant set as its own default — the right answer for a visitor whose
     * browser asks for neither English nor Amharic.
     *
     * @return array<string, string>
     */
    private static function alternatesFor(string $canonicalUrl): array
    {
        return [
            'en' => $canonicalUrl.'?lang=en',
            'am' => $canonicalUrl.'?lang=am',
            'x-default' => $canonicalUrl,
        ];
    }

    /**
     * Whether this request arrived on a hostname that names a tenant.
     *
     * Without APP_DOMAIN the hostname is not authoritative, so there is no such
     * thing as "this tenant's host" and the public surface does not exist —
     * which is what keeps a default clone, and single-host local development,
     * behaving exactly as they did before this feature. The apex, the platform
     * host and every reserved name resolve no tenant and land here too.
     */
    private function isTenantHost(): bool
    {
        return TenancyDomain::isHostnameAuthoritative()
            && $this->currentTenant->resolved();
    }
}
