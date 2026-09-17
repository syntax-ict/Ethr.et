<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Enums\UserRole;
use App\Http\Requests\Settings\UpsertPublicItemRequest;
use App\Http\Requests\Settings\UpsertPublicSectionRequest;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;

/**
 * The builder as an administrator uses it: caps, ordering, seeding and takedown.
 *
 * The caps are the part worth defending in review. A page builder with no limit
 * is a storage and page-weight denial of service that the platform pays for,
 * on shared hosting, for a page any anonymous visitor can request — and it
 * arrives through ordinary use rather than an attack.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

/** @return array{0: Tenant} */
function builderTenant(array $profile = []): array
{
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
        ...$profile,
    ]);

    return [$tenant];
}

// ── Caps ────────────────────────────────────────────────────────────────────

it('refuses to add a section past the cap', function () {
    [$tenant] = builderTenant();

    TenantPublicSection::factory()->count(UpsertPublicSectionRequest::MAX_SECTIONS)->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->postJson('http://habru.ethr.et/api/v1/settings/public-page/sections', [
        'kind' => PublicSectionKind::FAQ->value,
    ])->assertStatus(422);

    expect(TenantPublicSection::query()->count())->toBe(UpsertPublicSectionRequest::MAX_SECTIONS);
});

it('refuses to add an entry past the cap', function () {
    [$tenant] = builderTenant();

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
    ]);

    TenantPublicItem::factory()->count(UpsertPublicItemRequest::MAX_ITEMS)->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
    ]);

    $this->postJson(
        "http://habru.ethr.et/api/v1/settings/public-page/sections/{$section->public_id}/items",
        ['title' => 'One too many'],
    )->assertStatus(422);

    expect(TenantPublicItem::query()->where('section_id', $section->id)->count())
        ->toBe(UpsertPublicItemRequest::MAX_ITEMS);
});

// ── Ordering ────────────────────────────────────────────────────────────────

it('renumbers positions densely when reordered', function () {
    [$tenant] = builderTenant();

    $a = TenantPublicSection::factory()->create(['tenant_id' => $tenant->id, 'position' => 0]);
    $b = TenantPublicSection::factory()->create(['tenant_id' => $tenant->id, 'position' => 5]);
    $c = TenantPublicSection::factory()->create(['tenant_id' => $tenant->id, 'position' => 9]);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page/sections/order', [
        'order' => [$c->public_id, $a->public_id, $b->public_id],
    ])->assertOk();

    // Dense from zero, so there is never a gap for a later insert to collide
    // in and no unique constraint to dance around while reordering.
    expect([$c->fresh()->position, $a->fresh()->position, $b->fresh()->position])->toBe([0, 1, 2]);
});

it('renders sections in the stored order', function () {
    [$tenant] = builderTenant();

    // Two SERVICES sections, not two ABOUT ones: `about` reads the profile and
    // may appear only once, so a second is deliberately dropped — using it
    // here would be testing ordering against a page that renders one section.
    foreach ([['First heading', 0], ['Second heading', 1]] as [$heading, $position]) {
        $section = TenantPublicSection::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => PublicSectionKind::SERVICES,
            'position' => $position,
            'heading' => $heading,
        ]);

        TenantPublicItem::factory()->create([
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => "Entry {$position}",
        ]);
    }

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect(strpos($html, 'First heading'))->toBeLessThan(strpos($html, 'Second heading'));
});

// ── Seeding on opt-in ───────────────────────────────────────────────────────

it('seeds a preset default set the first time a tenant opts in', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'type' => 'hotel']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['preset' => 'hotel'])
        ->assertOk();

    $kinds = TenantPublicSection::query()->orderBy('position')->pluck('kind')
        ->map(fn ($k) => $k->value)->all();

    // The hotel preset leads with imagery; a government one would lead with
    // notices. That difference is the whole point of organisation-awareness.
    expect($kinds)->toContain('gallery')->toContain('hero');
});

it('does not publish placeholder headings when it seeds', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'type' => 'general']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'preset' => 'general',
        'is_published' => true,
        'description' => 'A real description the administrator wrote.',
    ])->assertOk();

    app(CurrentTenant::class)->forget();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // Blocks that need writing are seeded hidden, and a block with no content
    // renders nothing even if it were visible. "Loaded by default" must not
    // mean "an empty frame with headings over nothing".
    expect($html)
        ->toContain('A real description the administrator wrote.')
        ->not->toContain('Our services')
        ->not->toContain('Frequently asked questions');
});

it('adds only what is missing when the preset changes', function () {
    [$tenant] = builderTenant(['preset' => null]);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['preset' => 'general'])->assertOk();
    $afterFirst = TenantPublicSection::query()->count();

    // A tenant that has written content into its sections must not have that
    // duplicated or discarded because it changed layout.
    TenantPublicSection::query()->where('kind', PublicSectionKind::ABOUT)
        ->update(['heading' => 'Edited by hand']);

    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', ['preset' => 'hotel'])->assertOk();

    expect(TenantPublicSection::query()->where('kind', PublicSectionKind::ABOUT)->count())->toBe(1);
    expect(TenantPublicSection::query()->where('kind', PublicSectionKind::ABOUT)->first()->heading)
        ->toBe('Edited by hand');
    expect(TenantPublicSection::query()->count())->toBeGreaterThan($afterFirst);
});

// ── Platform takedown ───────────────────────────────────────────────────────

it('takes a page down without touching what the tenant chose', function () {
    [$tenant] = builderTenant();
    $profile = TenantPublicProfile::query()->firstOrFail();

    $this->get('http://habru.ethr.et/')->assertOk();

    $profile->forceFill(['suspended_at' => now()])->save();

    // Identical to an unpublished page — same status, same body — so a
    // takedown is not a way to discover which tenants have been moderated.
    $suspended = $this->get('http://habru.ethr.et/');
    $unknown = $this->get('http://nobody.ethr.et/');

    expect($suspended->getStatusCode())->toBe(404);
    expect($suspended->getContent())->toBe($unknown->getContent());

    // And the tenant's own publication state is untouched, so restoring is one
    // column write rather than a guess about what they had wanted.
    expect($profile->fresh()->is_published)->toBeTrue();
});

it('brings the page back when the suspension is lifted', function () {
    [$tenant] = builderTenant();
    $profile = TenantPublicProfile::query()->firstOrFail();

    $profile->forceFill(['suspended_at' => now()])->save();
    $this->get('http://habru.ethr.et/')->assertNotFound();

    $profile->forceFill(['suspended_at' => null])->save();
    $this->get('http://habru.ethr.et/')->assertOk();
});

it('does not let a tenant administrator lift its own suspension', function () {
    [$tenant] = builderTenant();
    $profile = TenantPublicProfile::query()->firstOrFail();
    $profile->forceFill(['suspended_at' => now()])->save();

    // `suspended_at` is absent from $fillable, so a settings payload carrying
    // it changes nothing. A takedown a tenant could lift is not a takedown.
    $this->putJson('http://habru.ethr.et/api/v1/settings/public-page', [
        'suspended_at' => null,
        'is_published' => true,
    ])->assertOk();

    expect($profile->fresh()->suspended_at)->not->toBeNull();
    $this->get('http://habru.ethr.et/')->assertNotFound();
});
