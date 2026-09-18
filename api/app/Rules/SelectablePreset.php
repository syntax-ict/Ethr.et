<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\PublicPagePreset;
use App\Models\Tenant;
use App\Services\Public\PresetResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Which landing-page presets this tenant is allowed to choose.
 *
 * Seven of the eight are free: a hotel presenting itself with the university
 * layout is a taste question, not a security one, and nobody is misled by a
 * page that looks academic.
 *
 * `government` is different. Ethiopian state branding on a private company's
 * public page tells visitors something untrue about who they are dealing with,
 * and `*.ethr.et` lends it ETHR's own domain. So it is the one preset that
 * cannot be self-granted.
 *
 * The tempting check — "is this tenant's industry a government one?" — is
 * worthless on its own, because both signals behind it are self-asserted:
 * `settings['industry']` is picked by the tenant at onboarding, and
 * `tenants.type` is validated as `string|max:50` with no `in:` rule
 * (UpdateOrganizationRequest). An attacker wanting the official look simply
 * claims to be a ministry, and a derived check waves them straight through.
 *
 * So the derived signal is the floor and `tenants.government_verified_at` is
 * the control: a human at the platform grants it through the `admin.manage`
 * surface, and no `/settings/*` route can write it. A tenant may ask; the
 * platform answers.
 */
class SelectablePreset implements ValidationRule
{
    public function __construct(private readonly Tenant $tenant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The selected :attribute is not a valid layout.');

            return;
        }

        $preset = PublicPagePreset::tryFrom($value);

        // tryFrom, not fromStorage: a *request* for an unknown preset is a
        // client error worth reporting. fromStorage's quiet fall back to
        // GENERAL exists for rows already in the database, where the only
        // acceptable outcome is that a visitor still gets a page.
        if ($preset === null) {
            $fail('The selected :attribute is not a valid layout.');

            return;
        }

        if (! $preset->requiresVerification()) {
            return;
        }

        if ($this->tenant->government_verified_at === null) {
            $fail('The government layout is available only to verified government organizations.');

            return;
        }

        // Verified *and* actually a government body. Belt and braces: a
        // verification granted years ago should not outlive the tenant
        // changing itself into something else.
        if (app(PresetResolver::class)->derive($this->tenant) !== PublicPagePreset::GOVERNMENT) {
            $fail('The government layout is available only to verified government organizations.');
        }
    }
}
