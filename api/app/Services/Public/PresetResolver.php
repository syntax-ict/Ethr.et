<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\PublicPagePreset;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\Onboarding\IndustryCatalog;

/**
 * Which layout a tenant's public page gets, and why.
 *
 * Four signals in descending order of authority. The order matters more than
 * any single step: an administrator's explicit choice must survive an
 * onboarding re-run, and a tenant that never chose anything must still get a
 * page that suits it rather than a generic one.
 *
 * The second signal is the interesting one. `tenants.settings['industry']` is
 * written by onboarding and validated against IndustryCatalog, which makes it
 * the most trustworthy thing available — but `settings` is also the column the
 * public page must never expose, because it carries `login_identifiers`. This
 * class is the boundary: it takes a Tenant and returns an enum case. The blob
 * is read here and nowhere near the view-model, so a template cannot reach it.
 */
final readonly class PresetResolver
{
    public function __construct(private IndustryCatalog $catalog) {}

    /**
     * The preset to render this tenant's page with.
     *
     * Never returns null: a page always renders as something.
     */
    public function resolve(Tenant $tenant, ?TenantPublicProfile $profile = null): PublicPagePreset
    {
        return $this->chosen($profile)
            ?? $this->fromIndustry($tenant)
            ?? $this->fromType($tenant)
            ?? PublicPagePreset::GENERAL;
    }

    /**
     * What the tenant would get if they had never chosen — the default offered
     * in settings, and what `SelectablePreset` measures a request against.
     *
     * Deliberately ignores the stored choice, so it cannot authorise itself:
     * asking "may this tenant pick government?" must not be answered by "it
     * already has government stored".
     */
    public function derive(Tenant $tenant): PublicPagePreset
    {
        return $this->fromIndustry($tenant)
            ?? $this->fromType($tenant)
            ?? PublicPagePreset::GENERAL;
    }

    private function chosen(?TenantPublicProfile $profile): ?PublicPagePreset
    {
        return PublicPagePreset::fromStorage($profile?->preset);
    }

    /**
     * Signal 2 — the onboarding industry, mapped through its base template.
     *
     * `settings` is cast to array on the model, but a tenant that never
     * completed onboarding has no key, and a hand-written row could hold
     * anything, so every step is guarded rather than assumed.
     */
    private function fromIndustry(Tenant $tenant): ?PublicPagePreset
    {
        $settings = $tenant->settings;

        if (! is_array($settings)) {
            return null;
        }

        $key = $settings['industry'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $industry = $this->catalog->find($key);

        if ($industry === null) {
            return null;
        }

        return PublicPagePreset::tryFrom($industry['base']);
    }

    /**
     * Signal 3 — `tenants.type`, which is free text and means three things.
     *
     * The column is `string|max:50` with no `in:` rule, and three separate
     * lists write to it: the registration form offers `general` and no
     * `private`, the settings card offers `private` and no `general`, and
     * onboarding writes an IndustryCatalog base. So this is a normalisation,
     * not a cast — `private` is a real value that means "an ordinary company"
     * and must land on GENERAL rather than on nothing.
     */
    private function fromType(Tenant $tenant): ?PublicPagePreset
    {
        $type = $tenant->type;

        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        return PublicPagePreset::tryFrom(mb_strtolower(trim($type)));
    }
}
