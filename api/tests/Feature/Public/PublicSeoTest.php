<?php

declare(strict_types=1);

use App\Enums\PublicPagePreset;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;

/**
 * The surfaces a search engine sees.
 *
 * The page exists to be found, so this is not decoration. Three properties
 * matter and each is easy to get subtly wrong:
 *
 *  - the structured-data type says what kind of organisation this is, which is
 *    the whole point of an organisation-aware layout;
 *  - both language variants are declared, because a bilingual page that
 *    advertises neither can only ever rank in whichever one got crawled;
 *  - robots.txt and sitemap.xml answer 404 in exactly the cases the page does,
 *    or they become a way to learn which organisations use ETHR.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function seoTenant(array $profile = [], array $tenant = []): Tenant
{
    $t = createTenant([
        'subdomain' => 'habru',
        'name' => 'Habru',
        ...$tenant,
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $t->id,
        'is_published' => true,
        'is_indexable' => true,
        'preset' => 'general',
        ...$profile,
    ]);

    app(CurrentTenant::class)->forget();

    return $t;
}

// ── Structured data ─────────────────────────────────────────────────────────

it('declares the schema type that matches the organisation kind', function (string $preset, string $type) {
    seoTenant(['preset' => $preset]);

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('"@type":"'.$type.'"', escape: false);
})->with([
    'government' => ['government', 'GovernmentOrganization'],
    'university' => ['university', 'CollegeOrUniversity'],
    'hospital' => ['hospital', 'Hospital'],
    'ngo' => ['ngo', 'NGO'],
    'bank' => ['bank', 'BankOrCreditUnion'],
    'hotel' => ['hotel', 'Hotel'],
    'manufacturing' => ['manufacturing', 'Organization'],
    'general' => ['general', 'Organization'],
]);

it('still produces parseable json-ld for every preset', function (string $preset) {
    seoTenant([
        'preset' => $preset,
        // The flags that stop a tenant closing the script element early are
        // the reason this is worth re-checking per preset rather than once.
        'headline' => 'Closing </script> and "quoting" & ampersands',
    ]);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    expect($m[1] ?? null)->not->toBeNull();
    expect(json_decode(trim($m[1]), true))->toBeArray();
})->with(array_map(
    static fn (PublicPagePreset $c): string => $c->value,
    PublicPagePreset::cases(),
));

// ── Language variants ───────────────────────────────────────────────────────

it('declares both language variants and a default', function () {
    seoTenant();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->toContain('hreflang="en"')
        ->toContain('hreflang="am"')
        ->toContain('hreflang="x-default"');
});

// ── robots.txt ──────────────────────────────────────────────────────────────

it('serves a robots file that points at the sitemap when indexing is allowed', function () {
    seoTenant();
    app()->detectEnvironment(fn () => 'production');

    $body = $this->get('http://habru.ethr.et/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($body)
        ->toContain('Allow: /')
        ->toContain('Sitemap: http://habru.ethr.et/sitemap.xml');
});

it('refuses every crawler when the tenant asked not to be indexed', function () {
    seoTenant(['is_indexable' => false]);
    app()->detectEnvironment(fn () => 'production');

    $body = $this->get('http://habru.ethr.et/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /')->not->toContain('Allow: /');
});

it('refuses every crawler outside production whatever the tenant chose', function () {
    seoTenant(['is_indexable' => true]);

    // A staging deployment answering for tenant hostnames would otherwise put
    // unfinished copies of a customer's page into search results.
    expect($this->get('http://habru.ethr.et/robots.txt')->assertOk()->getContent())
        ->toContain('Disallow: /');
});

// ── sitemap.xml ─────────────────────────────────────────────────────────────

it('serves a sitemap listing this tenants page and its language variants', function () {
    seoTenant();
    app()->detectEnvironment(fn () => 'production');

    $body = $this->get('http://habru.ethr.et/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect(simplexml_load_string($body))->not->toBeFalse();
    expect($body)
        ->toContain('<loc>http://habru.ethr.et/</loc>')
        ->toContain('hreflang="am"');
});

it('lists only this tenant, never a directory of tenants', function () {
    seoTenant();
    createTenant(['subdomain' => 'woldia', 'name' => 'Woldia Academy']);
    app(CurrentTenant::class)->forget();
    app()->detectEnvironment(fn () => 'production');

    // A platform-wide index of tenants is an unauthorised-discovery surface
    // wearing an SEO hat. The design document refuses it by name.
    expect($this->get('http://habru.ethr.et/sitemap.xml')->assertOk()->getContent())
        ->not->toContain('woldia');
});

it('omits the sitemap entirely for a page that asked not to be indexed', function () {
    seoTenant(['is_indexable' => false]);
    app()->detectEnvironment(fn () => 'production');

    // An empty sitemap is still a statement that the host serves a page.
    $this->get('http://habru.ethr.et/sitemap.xml')->assertNotFound();
});

// ── Neither file may become an oracle ───────────────────────────────────────

it('answers 404 for crawler files exactly as the page does', function (string $path) {
    // Unpublished, suspended, unknown and non-tenant hosts must all look the
    // same from outside. If robots.txt 200s where the page 404s, the pair
    // tells an anonymous visitor that the organisation exists and has chosen
    // not to publish — the inference the whole "404, never 403" rule prevents.
    $unknown = $this->get("http://nobody.ethr.et{$path}")->getStatusCode();

    $tenant = createTenant(['subdomain' => 'quiet']);
    TenantPublicProfile::factory()->create(['tenant_id' => $tenant->id, 'is_published' => false]);
    app(CurrentTenant::class)->forget();
    $unpublished = $this->get("http://quiet.ethr.et{$path}")->getStatusCode();

    $suspended = createTenant(['subdomain' => 'pulled']);
    $p = TenantPublicProfile::factory()->create(['tenant_id' => $suspended->id, 'is_published' => true]);
    $p->forceFill(['suspended_at' => now()])->save();
    app(CurrentTenant::class)->forget();
    $takenDown = $this->get("http://pulled.ethr.et{$path}")->getStatusCode();

    expect([$unknown, $unpublished, $takenDown])->toBe([404, 404, 404]);
})->with(['/', '/robots.txt', '/sitemap.xml']);
