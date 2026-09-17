<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;

/**
 * The public landing page at {tenant}.ethr.et/.
 *
 * The property most of this file defends is that **every failure looks the
 * same**. An unknown subdomain, a suspended organisation and one that simply
 * has not published all answer 404 with identical wording. Anything that
 * distinguishes them hands an anonymous visitor a way to enumerate which
 * organisations use ETHR, one hostname at a time — so a test that asserts 403
 * anywhere here would be asserting the bug.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

/** A tenant with a published public page. */
function publishedTenant(string $subdomain = 'habru', array $profile = []): Tenant
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain, 'name' => 'Habru Textiles']);

    app(CurrentTenant::class)->set($tenant);

    TenantPublicProfile::factory()->published()->create([
        'tenant_id' => $tenant->id,
        ...$profile,
    ]);

    app(CurrentTenant::class)->forget();

    return $tenant;
}

// ── The happy path ──────────────────────────────────────────────────────────

it('serves a published tenant page on that tenant hostname', function () {
    publishedTenant('habru', ['headline' => 'Weaving since 1974']);

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('Habru Textiles')
        ->assertSee('Weaving since 1974');
});

it('renders the tenant name in the title and a canonical url for its own host', function () {
    publishedTenant('habru');

    $response = $this->get('http://habru.ethr.et/');

    expect($response->getContent())
        ->toContain('<title>Habru Textiles')
        ->toContain('<link rel="canonical" href="http://habru.ethr.et/">');
});

it('offers employees a sign-in link on the same host, so the session cookie lands', function () {
    publishedTenant('habru');

    // Host-only cookies are the whole reason this must be a same-host link:
    // sending a visitor to the apex to sign in would leave them with a session
    // their tenant host cannot see.
    $this->get('http://habru.ethr.et/')->assertSee('href="/login"', false);
});

// ── Every way of not being served ───────────────────────────────────────────

it('answers 404 for a subdomain no tenant owns', function () {
    $this->get('http://nobody.ethr.et/')->assertNotFound();
});

it('answers 404, not 403, for a tenant that has not published', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'quiet']);
    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    // 403 would be the honest status and is precisely the leak: it separates
    // "exists but private" from "does not exist".
    $this->get('http://quiet.ethr.et/')->assertNotFound();
});

it('answers 404 for a tenant with no profile row at all', function () {
    Tenant::factory()->create(['subdomain' => 'blank']);

    $this->get('http://blank.ethr.et/')->assertNotFound();
});

it('gives an unpublished tenant and an unknown one the same body', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'quiet']);
    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    $unpublished = $this->get('http://quiet.ethr.et/')->getContent();
    $unknown = $this->get('http://nobody.ethr.et/')->getContent();

    expect($unpublished)->toBe($unknown);
});

it('stops serving a suspended tenant that had published', function () {
    $tenant = publishedTenant('habru');
    $tenant->update(['status' => TenantStatus::SUSPENDED]);

    $this->get('http://habru.ethr.et/')->assertNotFound();
});

it('stops serving a tenant whose trial has expired', function () {
    $tenant = publishedTenant('habru');
    $tenant->update(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);

    $this->get('http://habru.ethr.et/')->assertNotFound();
});

it('stops serving a soft-deleted tenant', function () {
    $tenant = publishedTenant('habru');
    $tenant->delete();

    $this->get('http://habru.ethr.et/')->assertNotFound();
});

// ── Hosts that must never resolve to a tenant ───────────────────────────────

/*
 * On a host that names no tenant, `/` answers exactly as it did before this
 * feature — the `welcome` view, 200. Registering `/` in routes/public.php
 * overrode the closure that used to live in routes/web.php, so the controller
 * has to keep that promise rather than 404 the apex.
 *
 * These assert the tenant's content is absent, which is the actual
 * requirement. Asserting a 404 instead would have hidden the regression.
 */
it('never serves a tenant page on the apex', function () {
    publishedTenant('habru');

    $this->get('http://ethr.et/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

it('never serves a tenant page on the platform host', function () {
    publishedTenant('habru');

    $this->get('http://admin.ethr.et/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

it('never serves a tenant page on a reserved subdomain', function (string $reserved) {
    publishedTenant('habru');

    $this->get("http://{$reserved}.ethr.et/")
        ->assertOk()
        ->assertDontSee('Habru Textiles');
})->with(Tenant::RESERVED_SUBDOMAINS);

it('does not treat a multi-label host as a tenant', function () {
    publishedTenant('habru');

    // `a.habru.ethr.et` is neither routed by nginx nor covered by the
    // certificate, so it must not quietly resolve to "a" — nor to habru.
    $this->get('http://evil.habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

it('does not treat a lookalike outside the domain as a tenant', function () {
    publishedTenant('habru');

    $this->get('http://habru.ethr.et.evil.com/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

// ── The master switch ───────────────────────────────────────────────────────

it('does not exist at all when APP_DOMAIN is unset', function () {
    config(['app.domain' => null]);
    publishedTenant('habru');

    // Without an authoritative hostname there is no such thing as "this
    // tenant's host", so the public surface does not exist and `/` is what it
    // always was. This is what keeps a default clone, and single-host local
    // development, behaving exactly as before.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

it('does not exist when APP_DOMAIN is blank rather than absent', function () {
    config(['app.domain' => '   ']);
    publishedTenant('habru');

    // `APP_DOMAIN=` in a .env file is an empty string, not null — the trap
    // TenancyDomain exists to close.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Habru Textiles');
});

it('still serves the welcome page at the apex, as routes/web.php used to', function () {
    // ExampleTest asserts GET / is 200. Registering `/` in routes/public.php
    // overrides the closure that used to answer it, so this pins the promise
    // that the override kept.
    config(['app.domain' => null]);

    $this->get('/')->assertOk();
});

// ── Client-supplied tenant selectors must not work here ─────────────────────

it('does not let X-Forwarded-Host choose which tenant page is served', function () {
    publishedTenant('habru');
    publishedTenant('woldia', ['headline' => 'Woldia headline']);

    $this->withHeaders(['X-Forwarded-Host' => 'woldia.ethr.et'])
        ->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Woldia headline');
});

it('does not let X-Tenant choose which tenant page is served', function () {
    publishedTenant('habru');
    publishedTenant('woldia', ['headline' => 'Woldia headline']);

    // X-Tenant is honoured in local/testing so the API can be driven without
    // subdomains — but only when the host names no tenant. A host that does
    // name one always wins, and this asserts that holds on the public page too.
    $this->withHeaders(['X-Tenant' => 'woldia'])
        ->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Woldia headline');
});

// ── Indexing ────────────────────────────────────────────────────────────────

it('marks a page noindex when the tenant asked for that', function () {
    publishedTenant('habru', ['is_indexable' => false]);

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('never lets a non-production deployment be indexed, whatever the tenant chose', function () {
    publishedTenant('habru', ['is_indexable' => true]);

    // dev.ethr.et answering for tenant hostnames would otherwise put duplicate,
    // half-finished copies of a customer's page into search results.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

// ── Caching ─────────────────────────────────────────────────────────────────

it('refuses to let the page be cached by a shared cache', function () {
    publishedTenant('habru');

    // The same path renders a different document on every tenant hostname. A
    // cache that keys on path alone would serve one tenant's page to another's
    // visitors — a cross-tenant leak arriving through infrastructure.
    $response = $this->get('http://habru.ethr.et/')->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
});

it('sets no cookie on the public page', function () {
    publishedTenant('habru');

    $response = $this->get('http://habru.ethr.et/')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});
