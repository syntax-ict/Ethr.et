<?php

declare(strict_types=1);

use App\Enums\PublicPagePreset;
use App\Enums\PublicSectionKind;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use App\Services\Public\SectionSeeder;
use App\Support\PublicSectionIcons;

/**
 * Every section kind and every icon is wired everywhere it has to be.
 *
 * The dullest file here and the one most likely to save someone. Adding a
 * twelfth section kind means touching an enum, a Blade partial and two
 * translation files, and the failure mode of missing one is silent — a blank
 * region on a real organisation's public page, discovered by a member of the
 * public rather than by CI.
 *
 * So these iterate the enums rather than naming values. A new case is covered
 * the moment it exists, and the failure says which case and which wiring point.
 */
it('gives every section kind a blade partial', function () {
    foreach (PublicSectionKind::cases() as $kind) {
        $path = resource_path("views/public/sections/{$kind->value}.blade.php");

        $this->assertFileExists(
            $path,
            "section kind {$kind->value} has no partial at views/public/sections/{$kind->value}.blade.php"
        );
    }
});

it('gives every section kind a heading in both locales', function () {
    foreach (PublicSectionKind::cases() as $kind) {
        foreach (['en', 'am'] as $locale) {
            $key = "public.sections.{$kind->value}.heading";
            $value = __($key, [], $locale);

            // Laravel returns the key itself when a translation is missing,
            // which renders as `public.sections.faq.heading` in an <h2> on a
            // live page — visibly broken rather than merely untranslated.
            $this->assertNotSame(
                $key,
                $value,
                "section kind {$kind->value} has no {$locale} heading"
            );
        }
    }
});

it('places every section kind in at least one preset default set', function () {
    $used = [];

    foreach (PublicPagePreset::cases() as $preset) {
        foreach (SectionSeeder::defaultsFor($preset) as $kind) {
            $used[$kind->value] = true;
        }
    }

    $orphans = array_diff(
        array_map(static fn (PublicSectionKind $k): string => $k->value, PublicSectionKind::cases()),
        array_keys($used),
    );

    // A kind no preset offers is a kind an administrator can only reach by
    // adding it by hand, which is not what "loaded by default" promised.
    $this->assertSame([], array_values($orphans), 'section kinds in no preset: '.implode(', ', $orphans));
});

it('renders every section kind without error', function (string $value) {
    config(['app.domain' => 'ethr.et']);

    $kind = PublicSectionKind::from($value);

    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
        'description' => 'Something to say.',
        'contact_phone' => '+251911223344',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => $kind,
        'is_visible' => true,
        'heading' => 'A heading',
        'intro' => 'An intro.',
    ]);

    if ($kind->hasItems()) {
        TenantPublicItem::factory()->create([
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'An entry',
            // The gallery renders pictures, not titles, so an entry without
            // one is deliberately dropped and the section disappears. Give it
            // an image so this test exercises the partial rather than the
            // empty-section rule.
            'image_path' => $kind === PublicSectionKind::GALLERY
                ? "tenants/{$tenant->public_id}/public/sections/a.png"
                : null,
            'image_alt' => $kind === PublicSectionKind::GALLERY ? 'A photograph' : null,
            'meta' => ['value' => '420', 'date' => '2026-03-01', 'opens' => '08:30', 'closes' => '17:00'],
        ]);
    }

    app(CurrentTenant::class)->forget();

    // The end-to-end version: a partial that references a variable the
    // view-model does not have fails here rather than in production.
    //
    // The hero is asserted differently because it is different: it owns the
    // page's only <h1> and shows the organisation's name there, rather than a
    // section heading. Asserting 'A heading' for it would be asserting the
    // wrong thing and then "fixing" the template to satisfy the test.
    $response = $this->get('http://habru.ethr.et/')->assertOk();

    $response->assertSee($kind === PublicSectionKind::HERO ? 'Habru' : 'A heading');
})->with(array_map(
    static fn (PublicSectionKind $k): string => $k->value,
    PublicSectionKind::cases(),
));

// ── Icons ───────────────────────────────────────────────────────────────────

it('matches the icon allow-list to the sprite in both directions', function () {
    $layout = file_get_contents(resource_path('views/public/tenant/landing-v2.blade.php'));

    preg_match_all('/<symbol id="icon-([a-z-]+)"/', $layout, $matches);
    $inSprite = $matches[1];

    $allowed = PublicSectionIcons::names();

    // Offered but not drawn renders an empty box on a real page; drawn but not
    // offered is dead weight nobody can reach. Both directions matter.
    $this->assertSame(
        [],
        array_values(array_diff($allowed, $inSprite)),
        'icons offered with no symbol: '.implode(', ', array_diff($allowed, $inSprite))
    );

    $this->assertSame(
        [],
        array_values(array_diff($inSprite, $allowed)),
        'symbols no one can select: '.implode(', ', array_diff($inSprite, $allowed))
    );
});
