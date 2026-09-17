<?php

declare(strict_types=1);

use App\Enums\PublicPagePreset;
use App\Models\TenantPublicProfile;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Public\PresetResolver;

/**
 * Which layout a tenant's public page gets.
 *
 * The precedence order is the whole design, so each step is tested against the
 * step below it rather than in isolation — "industry wins" only means something
 * if `type` says something different at the same time.
 *
 * The normalisation cases matter more than they look. `tenants.type` is
 * `string|max:50` with no `in:` rule and three different lists write to it, so
 * the values arriving here genuinely include `private`, `general`, null and
 * whatever someone typed. Every one of them has to land on a real page.
 */
function presetResolver(): PresetResolver
{
    return app(PresetResolver::class);
}

// ── Precedence ──────────────────────────────────────────────────────────────

it('prefers the administrators explicit choice over everything else', function () {
    $tenant = createTenant([
        'type' => 'hotel',
        'settings' => ['industry' => 'ministry'],
    ]);

    $profile = TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'preset' => 'university',
    ]);

    // Both derived signals say something else. A choice that loses to the data
    // it was meant to override is not a choice.
    expect(presetResolver()->resolve($tenant, $profile))->toBe(PublicPagePreset::UNIVERSITY);
});

it('prefers the onboarding industry over the free text type column', function () {
    $tenant = createTenant([
        'type' => 'hotel',
        'settings' => ['industry' => 'ministry'],
    ]);

    // `settings['industry']` is validated against IndustryCatalog; `type` is
    // validated as a string of at most fifty characters. The trustworthy one
    // wins.
    expect(presetResolver()->resolve($tenant))->toBe(PublicPagePreset::GOVERNMENT);
});

it('falls back to the type column when onboarding never ran', function () {
    $tenant = createTenant(['type' => 'hospital', 'settings' => null]);

    expect(presetResolver()->resolve($tenant))->toBe(PublicPagePreset::HOSPITAL);
});

it('falls back to general when a tenant says nothing about itself', function () {
    $tenant = createTenant(['type' => null, 'settings' => null]);

    expect(presetResolver()->resolve($tenant))->toBe(PublicPagePreset::GENERAL);
});

// ── Every industry in the catalog lands somewhere ───────────────────────────

it('maps every industry in the catalog onto a real preset', function () {
    $catalog = app(IndustryCatalog::class);

    // Guards the seam between two lists that are maintained separately. If
    // someone adds a ninth base template to IndustryCatalog without adding a
    // preset case, this names the industry that broke rather than leaving a
    // tenant on a silently generic page.
    foreach ($catalog->keys() as $key) {
        $tenant = createTenant(['type' => null, 'settings' => ['industry' => $key]]);

        expect(presetResolver()->resolve($tenant))
            ->toBeInstanceOf(PublicPagePreset::class, "industry {$key} resolved to nothing");
    }
});

// ── Normalising the free-text column ────────────────────────────────────────

it('treats the several ways of saying ordinary company as general', function (?string $type) {
    $tenant = createTenant(['type' => $type, 'settings' => null]);

    expect(presetResolver()->resolve($tenant))->toBe(PublicPagePreset::GENERAL);
})->with([
    // The registration form offers `general` and no `private`; the settings
    // card offers `private` and no `general`. Both are real stored values.
    'settings card' => 'private',
    'registration form' => 'general',
    'never set' => null,
    'empty string' => '',
    'whitespace' => '   ',
    'free text nobody validated' => 'a small textile cooperative',
]);

it('ignores capitalisation and stray whitespace in the type column', function () {
    $tenant = createTenant(['type' => '  GOVERNMENT ', 'settings' => null]);

    expect(presetResolver()->resolve($tenant))->toBe(PublicPagePreset::GOVERNMENT);
});

it('survives a settings blob that is not shaped how anyone expected', function (mixed $settings) {
    $tenant = createTenant(['type' => null]);
    // Written past the cast on purpose: the guards exist for rows that arrive
    // from somewhere other than this application's own writes.
    $tenant->forceFill(['settings' => $settings])->save();

    expect(presetResolver()->resolve($tenant->fresh()))->toBe(PublicPagePreset::GENERAL);
})->with([
    'no industry key' => [['login_identifiers' => ['email']]],
    'industry is not a string' => [['industry' => ['ministry']]],
    'industry is empty' => [['industry' => '']],
    'industry is unknown' => [['industry' => 'interstellar_mining']],
]);

// ── derive() must not authorise itself ──────────────────────────────────────

it('derives from the tenant alone, ignoring what is already stored', function () {
    $tenant = createTenant(['type' => 'hotel', 'settings' => null]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'preset' => 'government',
    ]);

    // This is what SelectablePreset measures a request against. If derive()
    // read the stored column, a tenant that had somehow got `government` in
    // there would be treated as entitled to it for ever after.
    expect(presetResolver()->derive($tenant))->toBe(PublicPagePreset::HOTEL);
});

// ── Storage degrades, requests do not ───────────────────────────────────────

it('degrades an unrecognised stored preset to general rather than throwing', function () {
    $tenant = createTenant(['type' => null, 'settings' => null]);

    $profile = TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id]);
    $profile->forceFill(['preset' => 'preset_from_a_future_migration'])->save();

    // An anonymous visitor must get a page. A 500 on the public internet
    // because a column holds a value this deploy has not heard of is a worse
    // outcome than a plain layout.
    expect(presetResolver()->resolve($tenant, $profile->fresh()))->toBe(PublicPagePreset::GENERAL);
});
