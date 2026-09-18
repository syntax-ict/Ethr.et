<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;

/**
 * Security headers on the public surface — and, just as importantly, the ones
 * still on the API.
 *
 * `SecurityHeaders` sends `default-src 'none'`, which is right for a JSON API
 * and blocks every stylesheet, font and image on an HTML page. The tempting fix
 * was to widen it; that would have relaxed the policy on every `/api/v1/*`
 * response in the application to serve one page. So the public group got its
 * own middleware and the API's policy was left alone.
 *
 * The last test in this file is the one that matters in six months: it fails if
 * anyone widens `SecurityHeaders` rather than adding to `PublicSecurityHeaders`.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function publishedTenantForHeaders(): Tenant
{
    $tenant = Tenant::factory()->create(['subdomain' => 'habru']);
    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    return $tenant;
}

it('allows the public page to load its own stylesheet, fonts and images', function () {
    publishedTenantForHeaders();

    $csp = $this->get('http://habru.ethr.et/')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain("style-src 'self'")
        ->toContain("font-src 'self'")
        ->toContain("img-src 'self'");
});

it('allows no script source at all on the public page', function () {
    publishedTenantForHeaders();

    $csp = $this->get('http://habru.ethr.et/')->assertOk()->headers->get('Content-Security-Policy');

    // The landing page ships no JavaScript. `default-src 'none'` already
    // forbids script, and there is deliberately no `script-src` widening it —
    // if that ever changes it should be a conspicuous edit, not an inherited one.
    expect($csp)->not->toContain('script-src');
});

it('does not open inline styles generally, only the attribute the theme needs', function () {
    publishedTenantForHeaders();

    $csp = $this->get('http://habru.ethr.et/')->assertOk()->headers->get('Content-Security-Policy');

    // `style-src 'self'` with no 'unsafe-inline' is why the page's CSS is a
    // static file. `style-src-attr` is the one named allowance, for the tenant
    // brand colours on <body>.
    expect($csp)
        ->toContain("style-src-attr 'unsafe-inline'")
        ->not->toContain("style-src 'self' 'unsafe-inline'");
});

it('refuses to be framed and refuses content sniffing', function () {
    publishedTenantForHeaders();

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('sends HSTS on tenant hosts, which is why TLS is a prerequisite of publishing', function () {
    publishedTenantForHeaders();

    // `includeSubDomains` means any browser that has seen ethr.et will refuse
    // plain http on every tenant host. A published tenant without a
    // certificate is unreachable, not merely insecure — see the publish runbook.
    $hsts = $this->get('http://habru.ethr.et/')->assertOk()->headers->get('Strict-Transport-Security');

    expect($hsts)->toContain('includeSubDomains');
});

it('sends the same headers on the not-found page', function () {
    // A 404 that arrives without headers is a 404 that can be framed.
    $this->get('http://nobody.ethr.et/')
        ->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('does not impose the tenant page CSP on the stock welcome view', function () {
    config(['app.domain' => null]);

    $response = $this->get('/')->assertOk();

    // The fallback on a non-tenant host is the `welcome` view, which loads a
    // webfont and uses inline styles. It is not a page this feature owns, and
    // the policy written for the tenant page only rendered it unstyled and
    // filled the console — caught in Chromium, not by any assertion here
    // before this one existed.
    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('still hardens the welcome fallback in every other way', function () {
    config(['app.domain' => null]);

    // Only the CSP is skipped. Framing, sniffing, referrer and HSTS are right
    // for any page and still apply.
    $this->get('/')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('leaks no internal marker header to the browser', function () {
    config(['app.domain' => null]);

    // The opt-out travels as a header the middleware strips on the way out.
    $this->get('/')->assertOk()->assertHeaderMissing('X-Ethr-Not-Public-Surface');
});

// ── The regression that matters ─────────────────────────────────────────────

it('leaves the API content security policy exactly as strict as it was', function () {
    $csp = $this->getJson('http://ethr.et/api/v1/ping')->headers->get('Content-Security-Policy');

    // If this fails, someone widened SecurityHeaders to make the HTML page
    // render instead of using PublicSecurityHeaders. That trades the security
    // of every API response for the styling of one page.
    expect($csp)->toBe(
        "default-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
});
