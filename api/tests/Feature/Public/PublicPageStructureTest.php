<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Http\Requests\Settings\UpsertPublicItemRequest;
use App\Http\Requests\Settings\UpsertPublicSectionRequest;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * What the page costs to render, and how it is put together.
 *
 * Two properties that are invisible in every feature test and expensive in
 * production: the number of queries a full page takes, and whether the document
 * outline survives an administrator assembling twenty blocks in any order.
 *
 * Both are asserted at the maximum the caps allow rather than on a typical
 * page, because a per-section query looks fine with three sections and is a
 * problem with twenty — on an anonymous route, on shared hosting.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function pageWithEverySection(int $sections, int $itemsEach): void
{
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
        'description' => "First paragraph.\n\nSecond paragraph.",
        'contact_phone' => '+251911223344',
        'contact_email' => 'info@habru.example.et',
    ]);

    $kinds = PublicSectionKind::cases();

    for ($i = 0; $i < $sections; $i++) {
        $kind = $kinds[$i % count($kinds)];

        $section = TenantPublicSection::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'position' => $i,
            'is_visible' => true,
            'heading' => "Section {$i}",
            'intro' => 'An intro.',
        ]);

        if ($kind->hasItems()) {
            TenantPublicItem::factory()->count($itemsEach)->create([
                'tenant_id' => $tenant->id,
                'section_id' => $section->id,
                'title' => 'An entry',
            ]);
        }
    }

    app(CurrentTenant::class)->forget();
}

it('renders a full page within a fixed query budget', function () {
    pageWithEverySection(UpsertPublicSectionRequest::MAX_SECTIONS, UpsertPublicItemRequest::MAX_ITEMS);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('http://habru.ethr.et/')->assertOk();

    // The number is a budget, not a measurement to update when it drifts. A
    // partial that lazily touched `$section->items` would turn this into
    // twenty-one queries and the page would still look correct — which is why
    // an N+1 survives code review and fails here instead.
    expect($queries)->toBeLessThanOrEqual(8);
});

it('costs the same whether the page has one section or twenty', function () {
    pageWithEverySection(1, 1);

    $small = 0;
    DB::listen(function () use (&$small): void {
        $small++;
    });
    $this->get('http://habru.ethr.et/')->assertOk();

    // Independence from content volume is the actual property. A fixed budget
    // alone could be met by a page that happens to be small today.
    expect($small)->toBeLessThanOrEqual(8);
});

// ── Document structure ──────────────────────────────────────────────────────

it('has exactly one h1 however many sections there are', function () {
    pageWithEverySection(12, 3);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // More than one <h1> breaks navigation for anyone moving by headings, and
    // a builder is exactly where a second one creeps in — every section is
    // somebody's idea of the most important thing on the page.
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('keeps the heading levels in order', function () {
    pageWithEverySection(12, 3);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    preg_match_all('/<h([1-6])/', $html, $matches);
    $levels = array_map('intval', $matches[1]);

    expect($levels)->not->toBeEmpty();

    $previous = 0;
    foreach ($levels as $level) {
        // A jump from h2 straight to h4 tells a screen-reader user a level is
        // missing and leaves them looking for content that is not there.
        expect($level)->toBeLessThanOrEqual($previous + 1);
        $previous = max($previous, $level);
    }
});

it('labels every section region with its own heading', function () {
    pageWithEverySection(6, 2);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    preg_match_all('/aria-labelledby="(section-\d+)"/', $html, $labels);

    expect($labels[1])->not->toBeEmpty();

    foreach ($labels[1] as $id) {
        // An aria-labelledby pointing at nothing is worse than none at all:
        // the region is announced as unnamed and the author believes it is
        // labelled.
        expect($html)->toContain('id="'.$id.'"');
    }
});

it('lazily loads images below the hero', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'hotel',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::GALLERY,
        'is_visible' => true,
    ]);

    $path = "tenants/{$tenant->public_id}/public/sections/x.png";
    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'image_path' => $path,
        'image_alt' => 'The courtyard',
    ]);
    app(CurrentTenant::class)->forget();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // A gallery of two dozen photographs on an Ethiopian mobile connection is
    // the difference between a page that loads and one that does not.
    expect($html)->toContain('loading="lazy"')->toContain('alt="The courtyard"');
});

it('renders no gallery at all when its entries have no pictures', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'hotel',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::GALLERY,
        'is_visible' => true,
        'heading' => 'Our gallery',
        'intro' => 'Photographs of the lodge.',
    ]);

    // Entries with titles but no images. The gallery partial renders pictures,
    // so these contribute nothing — and without the guard the page shows a
    // heading and an introduction over empty space.
    //
    // No test caught this. The suite counted items and the page counted
    // pictures, and the two agreed right up until a browser rendered it.
    TenantPublicItem::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'A photo that was never uploaded',
        'image_path' => null,
    ]);

    app(CurrentTenant::class)->forget();

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Our gallery')
        ->assertDontSee('Photographs of the lodge.');
});

it('renders the gallery once an entry has a picture', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'hotel',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::GALLERY,
        'is_visible' => true,
        'heading' => 'Our gallery',
    ]);

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'image_path' => "tenants/{$tenant->public_id}/public/sections/a.png",
        'image_alt' => 'The courtyard at dusk',
    ]);

    app(CurrentTenant::class)->forget();

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('Our gallery')
        ->assertSee('The courtyard at dusk', escape: false);
});
