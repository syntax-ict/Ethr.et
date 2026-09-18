<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-tenant isolation for the section builder.
 *
 * The builder adds two things that did not exist before: rows an anonymous
 * visitor can reach, and a route that takes an identifier from the URL. Both
 * are the shapes tenant isolation usually fails in, so this file exercises them
 * from the outside — with a real second tenant, on a real second hostname,
 * rather than by asserting that a query has a `where` clause in it.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
    Storage::fake(config('filesystems.default'));
});

/** @return array{0: Tenant, 1: TenantPublicSection, 2: TenantPublicItem} */
function tenantWithSection(string $subdomain, string $title): array
{
    $tenant = createTenant(['subdomain' => $subdomain, 'name' => ucfirst($subdomain)]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
        'is_visible' => true,
        'heading' => "{$title} heading",
    ]);

    $item = TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        'title' => $title,
    ]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $section, $item];
}

it('never renders one tenants sections on another tenants page', function () {
    tenantWithSection('habru', 'Habru only service');
    tenantWithSection('woldia', 'Woldia only service');

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('Habru only service')
        ->assertDontSee('Woldia only service')
        ->assertDontSee('Woldia only service heading');
});

it('refuses a section image addressed by another tenants identifier', function () {
    [, , $habruItem] = tenantWithSection('habru', 'Habru');
    tenantWithSection('woldia', 'Woldia');

    // Give the Habru item a real stored image so the only thing standing
    // between Woldia's host and the bytes is the tenant scope.
    $habru = Tenant::where('subdomain', 'habru')->firstOrFail();
    app(CurrentTenant::class)->set($habru);
    $path = "tenants/{$habru->public_id}/public/sections/x.png";
    Storage::disk(config('filesystems.default'))->put($path, 'png-bytes');
    $habruItem->forceFill(['image_path' => $path, 'image_alt' => 'A loom'])->save();
    app(CurrentTenant::class)->forget();

    // Its own host serves it.
    $this->get("http://habru.ethr.et/media/section/{$habruItem->public_id}")->assertOk();

    // Another tenant's host does not — and 404s rather than 403s, so the
    // response does not confirm that the identifier names anything.
    $this->get("http://woldia.ethr.et/media/section/{$habruItem->public_id}")->assertNotFound();
});

it('refuses a section image once its section is hidden', function () {
    [$tenant, $section, $item] = tenantWithSection('habru', 'Habru');

    app(CurrentTenant::class)->set($tenant);
    $path = "tenants/{$tenant->public_id}/public/sections/x.png";
    Storage::disk(config('filesystems.default'))->put($path, 'png-bytes');
    $item->forceFill(['image_path' => $path, 'image_alt' => 'A loom'])->save();
    app(CurrentTenant::class)->forget();

    $this->get("http://habru.ethr.et/media/section/{$item->public_id}")->assertOk();

    app(CurrentTenant::class)->set($tenant);
    $section->update(['is_visible' => false]);
    app(CurrentTenant::class)->forget();

    // Hiding a block has to hide what is inside it. Otherwise withdrawing a
    // notice would leave its photograph fetchable by anyone holding the URL.
    $this->get("http://habru.ethr.et/media/section/{$item->public_id}")->assertNotFound();
});

it('refuses section images for a tenant that has not published', function () {
    [$tenant, , $item] = tenantWithSection('habru', 'Habru');

    app(CurrentTenant::class)->set($tenant);
    $path = "tenants/{$tenant->public_id}/public/sections/x.png";
    Storage::disk(config('filesystems.default'))->put($path, 'png-bytes');
    $item->forceFill(['image_path' => $path, 'image_alt' => 'A loom'])->save();
    TenantPublicProfile::query()->where('tenant_id', $tenant->id)->update(['is_published' => false]);
    app(CurrentTenant::class)->forget();

    $this->get("http://habru.ethr.et/media/section/{$item->public_id}")->assertNotFound();
});

// ── The administration side ─────────────────────────────────────────────────

it('will not let an administrator edit another tenants section', function () {
    [, $habruSection] = tenantWithSection('habru', 'Habru');
    $woldia = Tenant::where('subdomain', 'woldia')->first()
        ?? tenantWithSection('woldia', 'Woldia')[0];

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $woldia);

    // 404 rather than 403: a 403 would confirm that the identifier names a
    // real section belonging to someone else.
    $this->putJson(
        "http://woldia.ethr.et/api/v1/settings/public-page/sections/{$habruSection->public_id}",
        ['heading' => 'Taken over'],
    )->assertNotFound();

    expect($habruSection->fresh()->heading)->toBe('Habru heading');
});

it('will not let an administrator delete another tenants section', function () {
    [, $habruSection] = tenantWithSection('habru', 'Habru');
    [$woldia] = tenantWithSection('woldia', 'Woldia');

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $woldia);

    $this->deleteJson(
        "http://woldia.ethr.et/api/v1/settings/public-page/sections/{$habruSection->public_id}"
    )->assertNotFound();

    expect(TenantPublicSection::withoutGlobalScopes()->whereKey($habruSection->id)->exists())->toBeTrue();
});

it('ignores another tenants identifiers in a reorder request', function () {
    [, $habruSection] = tenantWithSection('habru', 'Habru');
    [$woldia, $woldiaSection] = tenantWithSection('woldia', 'Woldia');

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $woldia);

    $this->putJson('http://woldia.ethr.et/api/v1/settings/public-page/sections/order', [
        'order' => [$habruSection->public_id, $woldiaSection->public_id],
    ])->assertOk();

    // Habru's section keeps its position; it was simply not in the scoped
    // result set. Reporting which ids were unknown would confirm the existence
    // of another tenant's rows, so the endpoint stays silent about them.
    expect($habruSection->fresh()->position)->toBe($habruSection->position);
});

it('will not let an administrator upload into another tenants item', function () {
    [, , $habruItem] = tenantWithSection('habru', 'Habru');
    [$woldia] = tenantWithSection('woldia', 'Woldia');

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $woldia);

    $this->postJson(
        "http://woldia.ethr.et/api/v1/settings/public-page/items/{$habruItem->public_id}/image",
        ['image' => UploadedFile::fake()->image('x.png'), 'alt' => 'anything'],
    )->assertNotFound();

    expect($habruItem->fresh()->image_path)->toBeNull();
});
