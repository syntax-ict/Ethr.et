<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\URL;

/**
 * Preview shows an administrator what nobody else can see — and nothing more.
 *
 * A rejected signature answers **404, not 403**, and that is not an accident:
 * RenderPublicErrorPage collapses every 4xx on this host to the same page,
 * because a 403 would confirm that `/preview` exists and that some signature
 * would work. The same reasoning as the landing page's unpublished 404.
 *
 * It renders unpublished pages and hidden sections, which is exactly why it has
 * to be airtight: it is a route on the anonymous host that deliberately serves
 * content the public route refuses. Everything here is about the gap between
 * those two being closed by the signature and nothing else.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function previewTenant(bool $published = false): Tenant
{
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => $published,
        'preset' => 'general',
        'headline' => 'A draft headline',
        'description' => 'Draft copy nobody has approved.',
    ]);

    $hidden = TenantPublicSection::factory()->hidden()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
        'heading' => 'A hidden section',
    ]);

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $hidden->id,
        'title' => 'A hidden service',
    ]);

    app(CurrentTenant::class)->forget();

    return $tenant;
}

function previewUrlFor(): string
{
    return URL::temporarySignedRoute('public.tenant.preview', now()->addMinutes(15), absolute: false);
}

// ── The signature is the whole authorisation ────────────────────────────────

it('refuses a preview with no signature', function () {
    previewTenant();

    $this->get('http://habru.ethr.et/preview')->assertNotFound();
});

it('refuses a preview whose signature has expired', function () {
    previewTenant();

    $url = URL::temporarySignedRoute('public.tenant.preview', now()->addMinutes(15), absolute: false);

    $this->travel(16)->minutes();

    // Fifteen minutes is short on purpose: a preview URL is shared in chat
    // messages and email threads, and one that worked for ever would be a
    // permanent bypass of the publication decision.
    $this->get('http://habru.ethr.et'.$url)->assertNotFound();
});

it('refuses a preview whose signature has been tampered with', function () {
    previewTenant();

    $url = previewUrlFor();

    $this->get('http://habru.ethr.et'.$url.'x')->assertNotFound();
});

it('refuses a signature minted for one tenant on another tenants host', function () {
    previewTenant();

    $other = createTenant(['subdomain' => 'woldia', 'name' => 'Woldia']);
    TenantPublicProfile::factory()->create(['tenant_id' => $other->id, 'preset' => 'general']);
    app(CurrentTenant::class)->forget();

    $url = previewUrlFor();

    // The signature is valid — it is a signature over a path, not over a
    // tenant. What stops it is that ResolveTenant reads the hostname, so the
    // preview on woldia.ethr.et is Woldia's own, never Habru's.
    $response = $this->get('http://woldia.ethr.et'.$url);

    $response->assertOk();
    $response->assertDontSee('A draft headline');
    $response->assertSee('Woldia');
});

// ── What it shows ───────────────────────────────────────────────────────────

it('renders a page the public route refuses', function () {
    previewTenant(published: false);

    // The public page 404s, because nothing is published.
    $this->get('http://habru.ethr.et/')->assertNotFound();

    // The preview shows it. That is the entire point: an administrator has to
    // be able to look before deciding to publish.
    $this->get('http://habru.ethr.et'.previewUrlFor())
        ->assertOk()
        ->assertSee('A draft headline');
});

it('shows hidden sections that the live page would not', function () {
    previewTenant(published: true);

    $this->get('http://habru.ethr.et/')->assertOk()->assertDontSee('A hidden section');

    $this->get('http://habru.ethr.et'.previewUrlFor())
        ->assertOk()
        ->assertSee('A hidden section');
});

it('says plainly that it is a preview', function () {
    previewTenant();

    // An administrator who cannot tell a preview from the live page will
    // either publish a draft or believe a draft is published.
    $this->get('http://habru.ethr.et'.previewUrlFor())
        ->assertOk()
        ->assertSee('not visible to the public', escape: false);
});

// ── It can never be indexed ─────────────────────────────────────────────────

it('always refuses indexing, whatever the tenant chose', function () {
    $tenant = previewTenant(published: true);

    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::query()->where('tenant_id', $tenant->id)->update(['is_indexable' => true]);
    app(CurrentTenant::class)->forget();

    $response = $this->get('http://habru.ethr.et'.previewUrlFor())->assertOk();

    // A preview URL that reached a search engine would publish exactly the
    // draft the administrator was still deciding about.
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
    $response->assertSee('noindex, nofollow', escape: false);
});

it('is never listed in the sitemap', function () {
    previewTenant(published: true);
    app()->detectEnvironment(fn () => 'production');

    expect($this->get('http://habru.ethr.et/sitemap.xml')->assertOk()->getContent())
        ->not->toContain('/preview');
});

// ── Minting is authenticated, even though using it is not ───────────────────

it('will not mint a preview url for an anonymous caller', function () {
    previewTenant();

    $this->postJson('http://habru.ethr.et/api/v1/settings/public-page/preview-url')
        ->assertUnauthorized();
});

it('mints a working url for an administrator', function () {
    $tenant = previewTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $url = $this->postJson('http://habru.ethr.et/api/v1/settings/public-page/preview-url')
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('https://habru.ethr.et/preview');

    // And the minted signature actually validates.
    $path = parse_url((string) $url, PHP_URL_PATH).'?'.parse_url((string) $url, PHP_URL_QUERY);
    $this->get('http://habru.ethr.et'.$path)->assertOk();
});
