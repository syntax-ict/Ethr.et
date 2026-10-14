<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use App\Services\PortalPreviewImage;
use App\Support\PortalHead;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The server-rendered head of an organisation's portal (organisation portal,
 * PR 4 of 4): what a search result, a shared link and a crawler see at
 * ethr.et/{slug}. These pin that it says what the page shows anyone and
 * nothing else, that it is escaped, that listing is opt-in, that the preview
 * image and the sitemap exist on the same terms as the other brand images.
 */

beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
    config(['app.frontend_url' => 'https://www.ethr.et', 'app.domain' => 'ethr.et']);
    app()->detectEnvironment(fn () => 'production');
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

function portalTenant(array $landing = [], array $attributes = []): Tenant
{
    return Tenant::factory()->create([
        'subdomain' => 'acme',
        'name' => 'Acme Manufacturing',
        'theme' => ['primary_color' => '#0E7490'],
        'landing' => ['background' => 'brand', ...$landing],
        ...$attributes,
    ]);
}

/** The exported page, served for the slug with a stand-in login.html. */
function servePortal(string $slug = 'acme'): TestResponse
{
    $page = public_path('login.html');
    $existed = is_file($page);
    $original = $existed ? file_get_contents($page) : null;
    file_put_contents($page, '<!doctype html><html lang="am"><head><meta charset="utf-8"><title>Sign in | ETHR</title><meta name="description" content="Sign in to your ETHR workspace."><meta name="theme-color" content="#0F4C75" media="(prefers-color-scheme: light)"><link rel="icon" href="/favicon.ico"></head><body><main id="login"></main></body></html>');

    try {
        return test()->get("http://ethr.et/{$slug}");
    } finally {
        $existed ? file_put_contents($page, $original) : unlink($page);
    }
}

it('renders the organisation into the page head, with its own title, description, canonical and alternates', function () {
    portalTenant([
        'message' => 'Check in when you arrive at the plant.',
        'headline_am' => 'እንኳን ደህና መጡ',
    ]);

    $html = servePortal()->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Acme Manufacturing | ETHR</title>')
        ->not->toContain('Sign in | ETHR')
        ->toContain('<meta name="description" content="Check in when you arrive at the plant.">')
        ->not->toContain('Sign in to your ETHR workspace.')
        ->toContain('<link rel="canonical" href="https://www.ethr.et/acme">')
        ->toContain('<link rel="alternate" hreflang="am" href="https://www.ethr.et/acme?lang=am">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="https://www.ethr.et/acme">')
        ->toContain('<meta name="theme-color" content="#0E7490">')
        ->not->toContain('content="#0F4C75"')
        ->toContain('<meta property="og:image" content="https://www.ethr.et/organisation-previews/')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        // No mark uploaded: the site's own icon stays.
        ->toContain('<link rel="icon" href="/favicon.ico">')
        ->toContain('<main id="login">');
});

it('keeps the page out of search results until listing is on, in the header and the head alike', function () {
    $tenant = portalTenant();

    $html = servePortal()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">');

    $tenant->update(['landing' => ['background' => 'brand', 'listed' => true]]);
    $html = servePortal()->assertHeader('X-Robots-Tag', 'index, follow')->getContent();
    expect($html)->toContain('<meta name="robots" content="index, follow">');
});

it('describes the organisation and its public vacancies as structured data, and nothing internal', function () {
    $tenant = portalTenant([
        'about' => 'Acme makes pumps in Adama.',
        'founded_year' => 1998,
        'staff_band' => '201-500',
        'address' => 'Industrial zone, Adama',
        'support_email' => 'hr@acme.et',
        'links' => ['website' => 'https://acme.et', 'telegram' => 'https://t.me/acme'],
    ]);
    app(CurrentTenant::class)->set($tenant);
    Vacancy::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Machine operator', 'employment_type' => 'full_time',
        'location' => 'Adama', 'description' => "Run the line.\n<b>Shifts.</b>", 'visibility' => 'public',
        'status' => 'open', 'published_at' => now()->subDay(), 'closes_on' => now()->addDays(20)->toDateString(),
    ]);
    Vacancy::query()->create([
        'tenant_id' => $tenant->id, 'title' => 'Internal transfer', 'employment_type' => 'full_time',
        'description' => 'Staff only.', 'visibility' => 'internal', 'status' => 'open', 'published_at' => now()->subDay(),
    ]);
    app(CurrentTenant::class)->forget();

    $html = servePortal()->getContent();
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $data = array_map(fn (string $json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);

    expect($data)->toHaveCount(2);
    [$organisation, $job] = $data;
    expect($organisation['@type'])->toBe('Organization')
        ->and($organisation['name'])->toBe('Acme Manufacturing')
        ->and($organisation['url'])->toBe('https://www.ethr.et/acme')
        ->and($organisation['sameAs'])->toBe(['https://acme.et', 'https://t.me/acme'])
        ->and($organisation['foundingDate'])->toBe('1998')
        ->and($organisation['numberOfEmployees'])->toBe(['@type' => 'QuantitativeValue', 'minValue' => 201, 'maxValue' => 500])
        ->and($organisation['email'])->toBe('hr@acme.et')
        ->and($job['@type'])->toBe('JobPosting')
        ->and($job['title'])->toBe('Machine operator')
        ->and($job['description'])->toBe("Run the line.\nShifts.")
        ->and($job['employmentType'])->toBe('FULL_TIME')
        ->and($job['hiringOrganization']['name'])->toBe('Acme Manufacturing')
        ->and($job['jobLocation']['address']['addressLocality'])->toBe('Adama')
        ->and($job['validThrough'])->toStartWith(now()->addDays(20)->toDateString())
        ->and($html)->not->toContain('Internal transfer')
        ->and($html)->not->toContain('Staff only');
});

it('escapes what the organisation wrote so it cannot leave its tag', function () {
    portalTenant(['message' => '"><script>alert(1)</script></head>', 'about' => 'x'], ['name' => 'Acme </script><b>']);

    $html = servePortal()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('Acme </script><b>')
        ->toContain('<title>Acme &lt;/script&gt;&lt;b&gt; | ETHR</title>')
        ->toContain('</script>');
});

it('falls back to a plain description and the ETHR mark when the organisation wrote nothing', function () {
    portalTenant();

    expect(PortalHead::description(['message' => null, 'about' => null], 'Acme'))
        ->toBe('Sign in to Acme on ETHR: attendance, leave and payslips.')
        ->and(PortalHead::description(['message' => null, 'about' => str_repeat('word ', 60)], 'Acme'))
        ->toHaveLength(160)
        ->toEndWith('…');
});

it('draws a preview image once per version and serves it immutable, and refuses a guessed version', function () {
    $tenant = portalTenant(['headline' => 'Welcome to the plant']);
    $version = PortalPreviewImage::version($tenant);

    $response = test()->get("http://ethr.et/organisation-previews/{$tenant->public_id}/{$version}")->assertOk();
    $bytes = (string) $response->getContent();
    [$width, $height] = getimagesizefromstring($bytes);

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and([$width, $height])->toBe([1200, 630])
        ->and(Storage::disk((string) config('filesystems.default'))->exists("tenants/{$tenant->public_id}/branding/preview-{$version}.png"))->toBeTrue();

    test()->get("http://ethr.et/organisation-previews/{$tenant->public_id}/0000000000000000")->assertNotFound();

    // A new headline is a new version, and the old URL stops answering.
    $tenant->update(['landing' => ['background' => 'brand', 'headline' => 'New words']]);
    expect(PortalPreviewImage::version($tenant->fresh()))->not->toBe($version);
    test()->get("http://ethr.et/organisation-previews/{$tenant->public_id}/{$version}")->assertNotFound();

    $tenant->update(['status' => TenantStatus::SUSPENDED]);
    test()->get("http://ethr.et/organisation-previews/{$tenant->public_id}/".PortalPreviewImage::version($tenant->fresh()))->assertNotFound();
});

it('lists only listed organisations in use in the portals sitemap, at their canonical address', function () {
    portalTenant(['listed' => true]);
    Tenant::factory()->create(['subdomain' => 'quiet', 'landing' => ['background' => 'brand']]);
    Tenant::factory()->create(['subdomain' => 'gone', 'status' => TenantStatus::SUSPENDED, 'landing' => ['background' => 'brand', 'listed' => true]]);

    $response = test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk();
    $xml = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('application/xml')
        ->and($xml)->toContain('<loc>https://www.ethr.et/acme</loc>')
        ->toContain('hreflang="am" href="https://www.ethr.et/acme?lang=am"')
        ->not->toContain('quiet')
        ->not->toContain('gone');
});
