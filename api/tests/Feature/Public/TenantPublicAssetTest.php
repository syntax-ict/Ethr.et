<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The public asset route — `/media/logo` and `/media/hero`.
 *
 * Every authenticated file in ETHR is served by a 15-minute signed URL. This
 * endpoint cannot be, because an `og:image` is fetched by a crawler days after
 * the page was rendered. What replaces the signature is the shape of the route:
 * it takes a *kind* from a two-item list and never a path, an id or a filename,
 * so there is no attacker-controlled component to sanitise.
 *
 * The tests below try to break that in each of the ways it could break —
 * traversal, cross-tenant access, an unpublished tenant's branding, and a
 * legacy external URL sitting in `tenants.logo_path`.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
    Storage::fake(config('filesystems.default'));
});

/**
 * A published tenant whose logo is a real object on the configured disk.
 *
 * @return array{0: Tenant, 1: string}
 */
function tenantWithLogo(string $subdomain = 'habru'): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    $path = "tenants/{$tenant->public_id}/public/logo/".Str::ulid().'.png';

    Storage::disk(config('filesystems.default'))->put($path, onePixelPng());
    $tenant->update(['logo_path' => $path]);

    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    return [$tenant, $path];
}

function onePixelPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

it('serves a published tenant logo with an image content type', function () {
    tenantWithLogo();

    $this->get('http://habru.ethr.et/media/logo')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('lets a browser cache the image, unlike the page', function () {
    tenantWithLogo();

    // Safe here and not on the HTML: an image URL carries the tenant hostname,
    // so a shared cache cannot confuse two tenants' logos the way it could
    // confuse two tenants' pages at the same `/` path.
    $response = $this->get('http://habru.ethr.et/media/logo')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public');
    expect($response->headers->get('ETag'))->not->toBeEmpty();
});

it('answers 304 when the browser already has the current image', function () {
    tenantWithLogo();

    $etag = $this->get('http://habru.ethr.et/media/logo')->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])
        ->get('http://habru.ethr.et/media/logo')
        ->assertStatus(304);
});

it('changes the ETag when the image is replaced', function () {
    [$tenant] = tenantWithLogo();

    $before = $this->get('http://habru.ethr.et/media/logo')->headers->get('ETag');

    $replacement = "tenants/{$tenant->public_id}/public/logo/".Str::ulid().'.png';
    Storage::disk(config('filesystems.default'))->put($replacement, onePixelPng());
    $tenant->update(['logo_path' => $replacement]);

    $after = $this->get('http://habru.ethr.et/media/logo')->headers->get('ETag');

    expect($after)->not->toBe($before);
});

// ── Visibility ──────────────────────────────────────────────────────────────

it('refuses the logo of a tenant that has not published', function () {
    [$tenant] = tenantWithLogo();

    TenantPublicProfile::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->update(['is_published' => false]);

    // Otherwise unpublishing would hide the page and leave the branding
    // fetchable by anyone who had seen the URL once.
    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
});

it('refuses the logo of a suspended tenant', function () {
    [$tenant] = tenantWithLogo();
    $tenant->update(['status' => TenantStatus::SUSPENDED]);

    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
});

// ── Cross-tenant ────────────────────────────────────────────────────────────

it('serves each tenant its own object, not the other tenants', function () {
    [, $habruPath] = tenantWithLogo('habru');
    [$woldia, $woldiaPath] = tenantWithLogo('woldia');

    // Distinct bytes per tenant, so "it served something" cannot be mistaken
    // for "it served the right thing".
    Storage::disk(config('filesystems.default'))->put($habruPath, 'HABRU-BYTES');
    Storage::disk(config('filesystems.default'))->put($woldiaPath, 'WOLDIA-BYTES');

    expect($this->get('http://habru.ethr.et/media/logo')->streamedContent())->toBe('HABRU-BYTES');
    expect($this->get('http://woldia.ethr.et/media/logo')->streamedContent())->toBe('WOLDIA-BYTES');
    expect($woldia->logo_path)->not->toBe($habruPath);
});

it('refuses a path belonging to another tenant even if it is stored on the row', function () {
    [$habru] = tenantWithLogo('habru');
    [$woldia] = tenantWithLogo('woldia');

    // Simulate the row being corrupted to point at another tenant's object.
    // TenantPublicAsset requires the path to start with this tenant's own
    // prefix, so the file is refused rather than served across the boundary.
    $woldia->update(['logo_path' => $habru->logo_path]);

    $this->get('http://woldia.ethr.et/media/logo')->assertNotFound();
});

// ── Malformed and legacy values ─────────────────────────────────────────────

it('refuses a stored path that climbs out of the tenant prefix', function () {
    [$tenant] = tenantWithLogo();

    $tenant->update(['logo_path' => "tenants/{$tenant->public_id}/../../etc/passwd"]);

    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
});

it('refuses a legacy external URL left in logo_path', function () {
    [$tenant] = tenantWithLogo();

    // `PUT /settings/branding` has always accepted `logo_url` as a bare string.
    // Rendering one publicly would make every anonymous visitor call a
    // third-party host. No logo is the correct way to fail.
    $tenant->update(['logo_path' => 'https://tracker.example.com/pixel.png']);

    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
    $this->get('http://habru.ethr.et/')->assertOk()->assertDontSee('tracker.example.com');
});

it('refuses a file whose extension is outside the fixed type map', function () {
    [$tenant] = tenantWithLogo();

    $path = "tenants/{$tenant->public_id}/public/logo/payload.svg";
    Storage::disk(config('filesystems.default'))->put($path, '<svg onload="alert(1)"></svg>');
    $tenant->update(['logo_path' => $path]);

    // SVG is markup a browser will execute. The content type is mapped from a
    // fixed list rather than sniffed, so an unmapped extension is a 404 and
    // never a guess.
    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
});

it('answers 404 when the row points at an object that is gone', function () {
    [$tenant, $path] = tenantWithLogo();

    Storage::disk(config('filesystems.default'))->delete($path);

    $this->get('http://habru.ethr.et/media/logo')->assertNotFound();
});

// ── Route shape ─────────────────────────────────────────────────────────────

it('accepts no kind outside logo and hero', function (string $kind) {
    tenantWithLogo();

    $this->get("http://habru.ethr.et/media/{$kind}")->assertNotFound();
})->with([
    'employees',
    'payroll',
    '..%2F..%2Fetc%2Fpasswd',
    'logo.png',
    'documents',
]);

it('does not exist on the apex or the platform host', function (string $host) {
    tenantWithLogo();

    $this->get("http://{$host}/media/logo")->assertNotFound();
})->with(['ethr.et', 'admin.ethr.et', 'www.ethr.et']);
