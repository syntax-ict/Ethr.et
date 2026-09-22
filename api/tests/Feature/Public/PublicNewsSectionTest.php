<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;

/**
 * The news section, and specifically what makes it not the notices section.
 *
 * The enum-iterating wiring tests already prove `news` has a partial, headings
 * in both locales and a place in a preset. This file covers the things that
 * are true of news and false of its neighbours, because "we already have
 * notices" is the first objection to this section existing and the answer has
 * to be demonstrable rather than asserted in a docblock.
 *
 * It also re-checks the boundary from the other direction. There are now two
 * section kinds whose names collide with internal HR features — `notices` and
 * `news` — and the `announcements` table is one careless join away from both.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

/** @return array{0: Tenant, 1: TenantPublicSection} */
function tenantWithNews(array $sectionAttributes = []): array
{
    $tenant = createTenant([
        'subdomain' => 'woldia',
        'name' => 'Woldia University',
        'ethiopian_calendar' => true,
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'university',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::NEWS,
        'is_visible' => true,
        'heading' => 'News and updates',
        ...$sectionAttributes,
    ]);

    return [$tenant, $section];
}

// ── What news is ────────────────────────────────────────────────────────────

it('renders each entry as its own article', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'A new wing opened',
    ]);

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://woldia.ethr.et/')->assertOk()->getContent();

    // <article>, not <li>: each entry is independently meaningful, which is
    // what the element means and what article navigation in a screen reader
    // relies on. The notices list is deliberately the other shape.
    expect(substr_count($html, '<article class="news__item">'))->toBe(2);
});

it('shows an entry that has no picture', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'A text-only announcement',
        'image_path' => null,
    ]);

    app(CurrentTenant::class)->forget();

    // The contrast with `gallery`, which drops imageless entries because its
    // partial renders nothing for them. News leads with a picture when there
    // is one and reads perfectly well without.
    $this->get('http://woldia.ethr.et/')
        ->assertOk()
        ->assertSee('A text-only announcement');
});

it('dates an entry in the tenants own calendar, with iso for machines', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'Graduation ceremony',
        'meta' => ['date' => '2026-03-01'],
    ]);

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://woldia.ethr.et/')->assertOk()->getContent();

    // 1 March 2026 is 22 Yekatit 2018 in the Ethiopian calendar. A reader on a
    // Woldia University page expects the second; a crawler needs the first.
    expect($html)
        ->toContain('datetime="2026-03-01"')
        ->toContain('22/06/2018');
});

it('marks outbound links so the page lends them nothing', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'Read the full story',
        'link_url' => 'https://example.et/story',
    ]);

    app(CurrentTenant::class)->forget();

    // Tenant-typed URLs, on a page this platform hosts under its own domain.
    $this->get('http://woldia.ethr.et/')
        ->assertOk()
        ->assertSee('rel="noopener noreferrer nofollow"', escape: false);
});

// ── News and notices are not the same section ───────────────────────────────

it('lets a tenant publish news and notices on the same page', function () {
    [$tenant] = tenantWithNews();

    $notices = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::NOTICES,
        'is_visible' => true,
        'heading' => 'Public notices',
        'position' => 5,
    ]);

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $notices->id,
        'title' => 'Tender for laboratory equipment',
    ]);

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => TenantPublicSection::query()
            ->where('kind', PublicSectionKind::NEWS)->firstOrFail()->id,
        'title' => 'Graduation ceremony',
    ]);

    app(CurrentTenant::class)->forget();

    // The whole justification for a separate kind: an organisation with both
    // should not have to choose which one to call it, and the two render
    // differently on the same page.
    $html = $this->get('http://woldia.ethr.et/')->assertOk()->getContent();

    // Matched on the block classes the partials actually emit — news carries a
    // layout modifier beside its own class, notices does not.
    expect($html)
        ->toContain('Tender for laboratory equipment')
        ->toContain('Graduation ceremony')
        ->toContain('<div class="news ')
        ->toContain('<ul class="notices">');
});

it('gives a government page notices above news', function () {
    $tenant = createTenant(['subdomain' => 'gov', 'name' => 'Woldia City Administration']);
    $tenant->forceFill(['government_verified_at' => now()])->save();

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'government',
    ]);

    foreach ([[PublicSectionKind::NOTICES, 'Statutory notice', 1], [PublicSectionKind::NEWS, 'Community news', 2]] as [$kind, $title, $position]) {
        $section = TenantPublicSection::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'is_visible' => true,
            'position' => $position,
        ]);

        TenantPublicItem::factory()->create([
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => $title,
        ]);
    }

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://gov.ethr.et/')->assertOk()->getContent();

    // Order is the tenant's to change, but the seeded default puts what a
    // citizen came looking for above what the office would like them to read.
    expect(strpos($html, 'Statutory notice'))->toBeLessThan(strpos($html, 'Community news'));
});

// ── The boundary, from the other direction ──────────────────────────────────

it('never publishes an internal announcement as news', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => 'A real published item',
    ]);

    app(CurrentTenant::class)->set($tenant);
    Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Payroll cut-off moved to the 25th',
        'body' => 'Internal: managers must approve timesheets before Friday.',
    ]);
    app(CurrentTenant::class)->forget();

    // There are now two public kinds whose names sound like the internal
    // announcements feature. Both are admin-typed content that happens to
    // share a word, and a future "obvious improvement" joining either to that
    // table would publish internal HR notices to the open internet.
    $this->get('http://woldia.ethr.et/')
        ->assertOk()
        ->assertSee('A real published item')
        ->assertDontSee('Payroll cut-off moved')
        ->assertDontSee('managers must approve timesheets');
});

it('escapes markup typed into a news entry', function () {
    [$tenant, $section] = tenantWithNews();

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => '<script>alert(1)</script>',
        'body' => '<img src=x onerror=alert(1)>',
    ]);

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://woldia.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=');
});
