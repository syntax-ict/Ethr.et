<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\CurrentTenant;
use App\Services\PortalPreviewImage;
use App\Support\Locales;
use App\Support\PortalHead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * The server-rendered head of an organisation's portal (organisation portal,
 * PR 4 of 4): what a search result, a shared link and a crawler see at
 * ethr.et/{slug}. These pin that it says what the page shows anyone and
 * nothing else, that it is escaped, that every organisation in use is listed unless it opted out, that the preview
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

/**
 * The exported page, served for the slug with a stand-in login.html.
 * `$payload` stands in for the React payload, so a test can serve a page
 * whose payload this release does not recognise.
 */
function servePortal(string $url = 'http://ethr.et/acme', ?string $payload = null): TestResponse
{
    $page = public_path('login.html');
    $existed = is_file($page);
    $original = $existed ? file_get_contents($page) : null;
    file_put_contents($page, '<!doctype html><html lang="am"><head><meta charset="utf-8"><title>Sign in | ETHR</title><meta name="description" content="Sign in to your ETHR workspace."><meta name="robots" content="noindex, follow"><meta name="theme-color" content="#0F4C75" media="(prefers-color-scheme: light)"><link rel="icon" href="/favicon.ico"></head><body><main id="login"></main>'.($payload ?? portalPayload('noindex, follow', 'Sign in to your ETHR workspace.')).'</body></html>');

    try {
        return test()->get($url);
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

it('lists an organisation in use by default, in the header and the head alike', function () {
    portalTenant();

    $html = servePortal()->assertHeader('X-Robots-Tag', 'index, follow')->getContent();
    expect($html)->toContain('<meta name="robots" content="index, follow">')
        ->not->toContain('noindex');
});

it('keeps the page out of search results once listing is switched off, or the organisation is not in use', function (array $landing, array $attributes) {
    portalTenant($landing, $attributes);

    $html = servePortal()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('content="index, follow"');
})->with([
    'switched off' => [['listed' => false], []],
    'a hand-edited value' => [['listed' => 'yes'], []],
    'suspended' => [[], ['status' => TenantStatus::SUSPENDED]],
    'an ended trial' => [[], ['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]],
]);

it('states the robots line in the page payload too, not only in the head', function (array $landing, string $robots) {
    portalTenant($landing);

    $html = servePortal()->getContent();

    // Both of them, because only one of them is what a crawler reads: the
    // head is the response, the payload is what React renders into the DOM.
    expect($html)->toContain('<meta name="robots" content="'.$robots.'">')
        ->toContain('\\"name\\":\\"robots\\",\\"content\\":\\"'.$robots.'\\"')
        ->not->toContain('noindex, follow');
})->with([
    'listed' => [[], 'index, follow'],
    'switched off' => [['listed' => false], 'noindex, nofollow'],
]);

it('reports a page that still says noindex, so a changed export cannot hide organisations quietly', function () {
    Log::spy();
    portalTenant();

    // A payload this release does not recognise: the same two keys, the other
    // way round. Next could serialise it this way after an upgrade, the
    // rewrite would match nothing, and every portal would go back to telling
    // Google `noindex` with no sign of it in the response. The scan is what
    // notices, because a test pinning today's shape cannot.
    $unknown = '<script>self.__next_f.push([1,"[\\"$\\",\\"meta\\",\\"3\\",{\\"content\\":\\"noindex, follow\\",\\"name\\":\\"robots\\"}]"]);</script>';

    expect(servePortal(payload: $unknown)->getContent())->toContain('noindex');

    Log::shouldHaveReceived('error')->once();
});

it('leaves the rest of the page payload exactly as exported', function () {
    portalTenant();

    $html = servePortal()->getContent();

    expect($html)->toContain('\\"rel\\":\\"manifest\\",\\"href\\":\\"/manifest.json\\"')
        ->toContain('\\"name\\":\\"mobile-web-app-capable\\",\\"content\\":\\"yes\\"');
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

it('lists every organisation in use in the portals sitemap unless it switched listing off, at its canonical address', function () {
    portalTenant();
    $trial = Tenant::factory()->create(['subdomain' => 'trialco', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addWeek(), 'landing' => null]);
    Employee::factory()->count(Tenant::PUBLIC_MIN_EMPLOYEES)->create(['tenant_id' => $trial->id]);
    // A trial nobody has been added to yet is not listed (onboarding review 2026-10-30).
    Tenant::factory()->create(['subdomain' => 'newco', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addWeek(), 'landing' => null]);
    Tenant::factory()->create(['subdomain' => 'quiet', 'landing' => ['background' => 'brand', 'listed' => false]]);
    Tenant::factory()->create(['subdomain' => 'gone', 'status' => TenantStatus::SUSPENDED, 'landing' => ['background' => 'brand', 'listed' => true]]);
    Tenant::factory()->create(['subdomain' => 'lapsed', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay(), 'landing' => ['background' => 'brand']]);

    $response = test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk();
    $xml = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('application/xml')
        ->and($xml)->toContain('<loc>https://www.ethr.et/acme</loc>')
        ->toContain('hreflang="am" href="https://www.ethr.et/acme?lang=am"')
        // A current trial with people and no portal settings saved yet is listed too.
        ->toContain('<loc>https://www.ethr.et/trialco</loc>')
        ->not->toContain('newco')
        ->not->toContain('quiet')
        ->not->toContain('gone')
        ->not->toContain('lapsed');
});

// QA 2026-11-14, D-1: the sitemap named en and am only, while the page names om as well.
it('names the same languages in the portals sitemap as the page itself declares', function () {
    portalTenant();

    $xml = (string) test()->get('http://ethr.et/sitemap-organisations.xml')->assertOk()->getContent();
    $html = (string) servePortal('http://ethr.et/acme')->getContent();

    foreach (Locales::SHIPPED as $locale) {
        expect($xml)->toContain('hreflang="'.$locale.'" href="https://www.ethr.et/acme?lang='.$locale.'"')
            ->and($html)->toContain('hreflang="'.$locale.'"');
    }
    expect(substr_count($xml, 'hreflang='))->toBe(count(Locales::SHIPPED) + 1);
});

// Organisation portal, PR 5: the same head on the organisation's own host.
it('renders the head on the organisation subdomain, with that host as the canonical address', function () {
    config(['tenancy.subdomains' => true]);
    portalTenant(['message' => 'Check in when you arrive.', 'listed' => true]);

    $html = servePortal('http://acme.ethr.et/login')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->getContent();

    expect($html)
        ->toContain('<title>Acme Manufacturing | ETHR</title>')
        ->toContain('<link rel="canonical" href="https://acme.ethr.et/login">')
        ->toContain('<link rel="alternate" hreflang="am" href="https://acme.ethr.et/login?lang=am">')
        ->toContain('<meta property="og:url" content="https://acme.ethr.et/login">')
        ->toContain('"url":"https://acme.ethr.et/login"')
        ->toContain('<main id="login">');
});

it('renders the head on a verified custom domain, and not on one still pending', function () {
    portalTenant([], ['custom_domain' => 'hr.acme.com', 'custom_domain_verified_at' => now(), 'address_mode' => 'custom_domain']);

    $html = servePortal('http://hr.acme.com/login')->assertOk()->getContent();
    expect($html)
        ->toContain('<title>Acme Manufacturing | ETHR</title>')
        ->toContain('<link rel="canonical" href="https://hr.acme.com/login">');

    Tenant::factory()->create(['subdomain' => 'pending', 'name' => 'Pending Ltd', 'custom_domain' => 'hr.pending.com', 'landing' => ['background' => 'brand']]);
    $html = servePortal('http://hr.pending.com/login')->assertOk()->getContent();
    expect($html)
        ->toContain('<title>Sign in | ETHR</title>')
        ->not->toContain('Pending Ltd');
});

it('serves the page as exported on the apex, the console host, an unknown host and a suspended organisation', function (string $url) {
    config(['tenancy.subdomains' => true]);
    portalTenant([], ['status' => $url === 'http://acme.ethr.et/login' ? TenantStatus::SUSPENDED : TenantStatus::ACTIVE]);

    $html = servePortal($url)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->getContent();

    expect($html)
        ->toContain('<title>Sign in | ETHR</title>')
        ->not->toContain('Acme Manufacturing');
})->with([
    'the apex' => 'http://ethr.et/login',
    'www' => 'http://www.ethr.et/login',
    'the console host' => 'http://admin.ethr.et/login',
    'a subdomain nobody holds' => 'http://nobody.ethr.et/login',
    'a stranger domain' => 'http://stranger.example/login',
    'a suspended organisation' => 'http://acme.ethr.et/login',
]);

it('states the organisation\'s description in the page payload too, so hydration cannot add a second one', function () {
    portalTenant(['message' => 'Check in when you arrive at the plant.']);
    Log::spy();

    $html = servePortal()->getContent();

    // The head and the payload agree, and the export's own line is gone from
    // both: React renders the payload's tag into the DOM at hydration.
    expect(portalPayloadMeta($html, 'description'))->toBe(['Check in when you arrive at the plant.'])
        ->and(substr_count($html, 'name="description"'))->toBe(1)
        ->and($html)->not->toContain('Sign in to your ETHR workspace.');
    Log::shouldNotHaveReceived('error');
});

it('writes a description a person wrote into the payload so that it reads back exactly, and cannot close its script', function () {
    $written = "He said \"stop\" at C:\plant\ & <b>left</b> </script><script>alert(1)</script>\nትምህርት 'quoted'";
    $head = '<meta name="description" content="'.htmlspecialchars($written, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
    $page = '<html><head><title>x</title></head><body>'.portalPayload('noindex, follow', 'Sign in.').'</body></html>';

    $html = PortalHead::inject($page, $head, false, 'index, follow');

    expect(portalPayloadMeta($html, 'description'))->toBe([$written])
        // Nothing in the value can end the payload's own script: the only
        // closing tag in the page is the payload's, and its only `<` and `&`
        // are the page's own markup.
        ->and(substr_count($html, '</script>'))->toBe(1)
        ->and(substr_count($html, '<script>'))->toBe(1);
});

it('replaces a payload value whole when it holds an escaped quote or ends in a backslash', function (string $exported) {
    $head = '<meta name="description" content="Our own words">';
    $page = '<html><head></head><body>'.portalPayload('noindex, follow', $exported).'</body></html>';

    $html = PortalHead::inject($page, $head, false, 'index, follow');

    // Equal to the page as it would have been exported with our words in it:
    // everything around the value is byte for byte what it was.
    expect($html)->toBe('<html><head><meta name="description" content="Our own words">'."\n".'</head><body>'.portalPayload('index, follow', 'Our own words').'</body></html>');
})->with([
    'a quote inside the value' => ['He said \\\\\\"hi\\\\\\" twice'],
    'a backslash at the end' => ['ends here\\\\\\\\'],
    'a quote after a backslash' => ['a\\\\\\\\\\\\\\"b'],
    'plain' => ['News and announcements.'],
]);

it('leaves a payload with no description entry as it is, and states none when the head has none', function () {
    $page = '<html><head></head><body>'.portalPayload('noindex, follow').'</body></html>';

    expect(PortalHead::inject($page, '<meta name="robots" content="index, follow">', false, 'index, follow'))
        ->toContain(portalPayload('index, follow'))
        ->and(PortalHead::inject('<html><head></head><body>'.portalPayload('noindex, follow', 'Old').'</body></html>', '<title>x</title>', false, 'index, follow'))
        ->toContain(portalPayload('index, follow', 'Old'));
});

it('reports a payload whose description it cannot recognise, and still serves the page', function () {
    Log::spy();
    portalTenant(['message' => 'Check in when you arrive at the plant.']);

    // The same two keys, the other way round, as a changed export might write them.
    $unknown = '<script>self.__next_f.push([1,"[\\"$\\",\\"meta\\",\\"3\\",{\\"content\\":\\"Sign in.\\",\\"name\\":\\"description\\"}]"]);</script>';

    servePortal(payload: $unknown)->assertOk();

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'description'));
});

it('follows the organisation\'s own language in og:locale, and lists the other two as alternates', function (string $locale, string $primary, array $others) {
    portalTenant([], ['default_locale' => $locale]);

    $html = servePortal()->getContent();

    expect($html)->toContain('<meta property="og:locale" content="'.$primary.'">')
        ->and(substr_count($html, 'property="og:locale"'))->toBe(1);
    foreach ($others as $other) {
        expect($html)->toContain('<meta property="og:locale:alternate" content="'.$other.'">');
    }
    expect(substr_count($html, 'og:locale:alternate'))->toBe(2);
})->with([
    'english' => ['en', 'en_US', ['am_ET', 'om_ET']],
    'amharic' => ['am', 'am_ET', ['en_US', 'om_ET']],
    'afaan oromoo' => ['om', 'om_ET', ['en_US', 'am_ET']],
    'a language not shipped' => ['ti', 'en_US', ['am_ET', 'om_ET']],
]);

it('doubles a leading dollar sign in the payload, as the payload writes a literal one', function () {
    $head = '<meta name="description" content="$5 off every Friday">';
    $page = '<html><head></head><body>'.portalPayload('noindex, follow', 'Sign in.').'</body></html>';

    expect(portalPayloadMeta(PortalHead::inject($page, $head, false, 'index, follow'), 'description'))->toBe(['$$5 off every Friday']);
});
