<?php

declare(strict_types=1);

use App\Enums\PublicPagePreset;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * The rollout guarantee: deploying presets changes no live page.
 *
 * Every `tenant_public_profiles` row that exists when the migration runs gets
 * `preset = null`, and a null preset renders the classic template. So a tenant
 * that published months ago sees exactly what it saw yesterday until an
 * administrator opts in — nobody's public page changes appearance because we
 * deployed.
 *
 * That is a promise about bytes, so it is tested as bytes: the rendered page is
 * compared against a committed snapshot rather than spot-checked for a few
 * elements, which would pass while the layout drifted around them.
 *
 * The clock is frozen for a reason. The footer renders `now()->year`
 * (landing.blade.php:170), so a snapshot taken in one year and compared in the
 * next fails on 1 January — every build, at the worst possible moment to be
 * debugging a mystery. Freezing is the whole fix; nothing else is normalised,
 * because normalising away differences is how a byte-comparison test quietly
 * stops comparing bytes.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00'));
});

const CLASSIC_SNAPSHOT = __DIR__.'/snapshots/classic-landing.html';

function classicTenant(?string $preset = null): Tenant
{
    $tenant = createTenant([
        'subdomain' => 'habru',
        'name' => 'Habru Textiles Share Company',
        'type' => 'manufacturing',
        'theme' => [
            'primary_color' => '#0f4c75',
            'secondary_color' => '#3282b8',
            'accent_color' => '#e8a838',
        ],
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'is_indexable' => true,
        'preset' => $preset,
        'headline' => 'Weaving in Amhara since 1974',
        'description' => "A textile cooperative in Woldia.\n\nWe employ 420 people.",
        'contact_email' => 'info@habru.example.et',
        'contact_phone' => '+251911223344',
        'address_line' => 'Kebele 04, Industrial Road',
        'city' => 'Woldia',
        'region' => 'Amhara',
        'website_url' => 'https://habru.example.et',
        'social_links' => ['telegram' => 'https://t.me/habrutextiles'],
        'meta_description' => 'A worker-owned textile cooperative in Woldia.',
        'published_at' => now(),
    ]);

    app(CurrentTenant::class)->forget();

    return $tenant;
}

it('renders a tenant that never opted in byte for byte as before', function () {
    classicTenant(preset: null);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // Regenerate deliberately, never casually: a diff here means either a
    // genuine change to the classic page — which no tenant asked for — or that
    // someone edited landing.blade.php while a snapshot promised they had not.
    if (! file_exists(CLASSIC_SNAPSHOT)) {
        @mkdir(dirname(CLASSIC_SNAPSHOT), 0755, true);
        file_put_contents(CLASSIC_SNAPSHOT, $html);
    }

    expect($html)->toBe(file_get_contents(CLASSIC_SNAPSHOT));
});

it('puts no preset class on the classic page', function () {
    classicTenant(preset: null);

    // The skin is opt-in too. A body class leaking onto the classic layout
    // would change its appearance without changing its template.
    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)->not->toContain('preset--');
});

it('renders the preset layout only once a preset is stored', function () {
    classicTenant(preset: 'government');

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->toContain('preset--government')
        ->not->toBe(file_get_contents(CLASSIC_SNAPSHOT));
});

it('skins each preset with its own class', function (string $preset) {
    classicTenant(preset: $preset);

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('preset--'.$preset, escape: false);
})->with(array_map(
    static fn (PublicPagePreset $case): string => $case->value,
    // `government` is excluded: the stored value is honoured at render time
    // whatever it says, but seeding one here would imply an unverified tenant
    // could reach it, which GovernmentPresetLockTest exists to deny.
    array_filter(PublicPagePreset::cases(), static fn ($c) => $c !== PublicPagePreset::GOVERNMENT),
));

it('keeps the classic pages structured data generic whatever the tenant does', function () {
    // A hospital that never opted in. Its derived preset is HOSPITAL, and
    // wiring the schema type to the *resolved* preset rather than the *stored*
    // one would quietly change this page's JSON-LD from Organization to
    // Hospital on deploy — invisible to a visitor, visible to every crawler,
    // and exactly the kind of change the opt-in exists to prevent.
    //
    // The byte snapshot alone does not catch this: its fixture is a
    // manufacturing tenant, and manufacturing maps to Organization anyway.
    $tenant = createTenant([
        'subdomain' => 'tikur',
        'name' => 'Tikur Anbessa',
        'type' => 'hospital',
        'settings' => ['industry' => 'hospital'],
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => null,
    ]);

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://tikur.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->toContain('"@type":"Organization"')
        ->not->toContain('"@type":"Hospital"');
});

it('uses the specific structured data type once that tenant opts in', function () {
    $tenant = createTenant([
        'subdomain' => 'tikur',
        'name' => 'Tikur Anbessa',
        'type' => 'hospital',
        'settings' => ['industry' => 'hospital'],
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'hospital',
    ]);

    app(CurrentTenant::class)->forget();

    $this->get('http://tikur.ethr.et/')
        ->assertOk()
        ->assertSee('"@type":"Hospital"', escape: false);
});
