<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use App\Support\PublicTenantPage;
use App\Support\TenancyDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function __invoke(Request $request): Response
    {
        // Registering `/` here overrides whatever `routes/web.php` had for it,
        // because Laravel's route collection is keyed by method and URI and the
        // last registration wins. So this controller has to answer for every
        // host, not just tenant ones — and on a non-tenant host it must answer
        // exactly as before, or a request to the apex starts 404ing.
        if (! $this->isTenantHost()) {
            return response()->view('welcome');
        }

        $page = $this->resolvePage();

        $canonicalUrl = $request->getSchemeAndHttpHost().'/';

        $response = response()->view('public.tenant.landing', [
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

    /**
     * The page for this host, or a 404 that says nothing about why.
     */
    private function resolvePage(): PublicTenantPage
    {
        $tenant = $this->currentTenant->get();

        if ($tenant === null || ! $tenant->isActive()) {
            throw new NotFoundHttpException;
        }

        // Scoped by BelongsToTenant. Stated as a `where` anyway rather than
        // relying on the global scope alone: the two are the same query today,
        // and if a future caller ever reaches this method without a resolved
        // tenant, the explicit predicate is what makes the result empty instead
        // of arbitrary.
        $profile = TenantPublicProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->first();

        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return PublicTenantPage::from($tenant, $profile);
    }
}
