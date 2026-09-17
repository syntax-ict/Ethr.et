<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use App\Support\TenancyDomain;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one place that decides whether a host has a public page.
 *
 * Four anonymous surfaces ask that question — the landing page, robots.txt,
 * sitemap.xml and the signed preview — and they must answer identically. Not
 * for tidiness: the moment one of them omits a clause, it becomes an oracle.
 * A sitemap that 200s for a tenant whose page 404s tells an anonymous visitor
 * that the organisation exists and has chosen not to publish, which is exactly
 * the inference the landing page's "404, never 403" rule exists to prevent.
 *
 * So the rules live here once:
 *
 *  - the hostname must be authoritative and name a tenant
 *  - the tenant must be active
 *  - the profile must exist, be published, and not be suspended by the platform
 *
 * Every failure is the same NotFoundHttpException with nothing distinguishing
 * it, so the four surfaces agree by construction rather than by review.
 */
final readonly class PublishedTenantLocator
{
    public function __construct(private CurrentTenant $currentTenant) {}

    /**
     * The tenant and its published profile, or a 404 that says nothing.
     *
     * @return array{0: Tenant, 1: TenantPublicProfile}
     */
    public function locate(): array
    {
        $tenant = $this->tenant();

        // Scoped by BelongsToTenant. The explicit predicate is stated anyway:
        // the two are the same query today, and if a caller ever reaches this
        // without a resolved tenant the predicate is what makes the result
        // empty rather than arbitrary.
        $profile = TenantPublicProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->whereNull('suspended_at')
            ->first();

        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return [$tenant, $profile];
    }

    /**
     * The tenant for this host, published or not.
     *
     * Used by preview, which must render a page the public cannot see. It
     * still refuses a non-tenant host and an inactive tenant, so preview never
     * becomes a way to reach something the landing page would not resolve.
     */
    public function tenant(): Tenant
    {
        if (! TenancyDomain::isHostnameAuthoritative() || ! $this->currentTenant->resolved()) {
            throw new NotFoundHttpException;
        }

        $tenant = $this->currentTenant->get();

        if ($tenant === null || ! $tenant->isActive()) {
            throw new NotFoundHttpException;
        }

        return $tenant;
    }
}
