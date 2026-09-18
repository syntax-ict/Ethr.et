<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;

/**
 * Tenant-entered text reaching an anonymous visitor's browser.
 *
 * Blade escapes by default, so most of this asserts that nothing has quietly
 * opted out of that. The cases worth writing down are the ones where escaping
 * alone is not the answer: a URL field, where the payload is the value rather
 * than the markup around it, and the JSON-LD block, where the escaping rules
 * are JSON's and not HTML's.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function tenantWithContent(array $profile = [], array $tenantAttributes = []): Tenant
{
    $tenant = Tenant::factory()->create([
        'subdomain' => 'habru',
        'name' => 'Habru Textiles',
        ...$tenantAttributes,
    ]);

    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id, ...$profile]);
    app(CurrentTenant::class)->forget();

    return $tenant;
}

it('escapes script markup typed into any text field', function (string $field) {
    tenantWithContent([$field => '<script>alert("xss")</script>']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert')
        ->toContain('&lt;script&gt;');
})->with(['headline', 'description', 'address_line', 'city', 'region']);

it('escapes script markup in the organisation name', function () {
    tenantWithContent([], ['name' => '<script>alert(1)</script>Habru']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
});

it('does not let a closing script tag in tenant text break out of the JSON-LD block', function () {
    tenantWithContent([
        'meta_description' => '</script><script>alert(1)</script>',
    ]);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // JSON_HEX_TAG is what does this: without it the literal `</script>` would
    // close the element early and everything after it would be parsed as
    // markup. Escaping for HTML would produce invalid JSON instead, which is
    // why the flag and not e() is the right tool here.
    expect($html)->not->toContain('</script><script>');
});

it('escapes a quote-breaking payload in an attribute', function () {
    tenantWithContent([], ['name' => 'Habru" onload="alert(1)']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)->not->toContain('onload="alert(1)"');
});

// ── URLs: escaping is not enough, the value itself must be refused ──────────

it('never renders a javascript URL that reached the column', function () {
    tenantWithContent(['website_url' => 'javascript:alert(1)']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // Validated at write time and again in PublicTenantPage. The second check
    // is not redundant: these rows are long-lived and editable by more than one
    // code path, and a payload that arrived through any of them must not reach
    // an anchor tag because of where it happened to be checked.
    expect($html)->not->toContain('javascript:alert');
});

it('never renders a data URL that reached the column', function () {
    tenantWithContent(['website_url' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==']);

    expect($this->get('http://habru.ethr.et/')->getContent())->not->toContain('data:text/html');
});

it('drops a social link that is not on that platforms own host', function () {
    tenantWithContent(['social_links' => ['facebook' => 'https://phishing.example.com/habru']]);

    // Otherwise "Facebook" is just a label on a link pointing anywhere, and a
    // visitor clicks it believing the destination.
    expect($this->get('http://habru.ethr.et/')->getContent())
        ->not->toContain('phishing.example.com');
});

it('keeps a social link that is on the platforms own host', function () {
    tenantWithContent(['social_links' => ['telegram' => 'https://t.me/habru']]);

    $this->get('http://habru.ethr.et/')->assertOk()->assertSee('https://t.me/habru', false);
});

it('marks outbound links so they cannot reach back into the page', function () {
    tenantWithContent(['website_url' => 'https://habru.example.com']);

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('rel="noopener noreferrer nofollow"', false);
});

// ── Colours are interpolated into a style attribute ─────────────────────────

it('ignores a theme colour that is not a plain hex value', function () {
    tenantWithContent([], ['theme' => [
        'primary_color' => 'red; background: url(javascript:alert(1))',
        'accent_color' => '#E8A838',
    ]]);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('javascript:alert')
        ->toContain('#E8A838');
});

// ── The rule that keeps all of the above true ───────────────────────────────

it('uses no unescaped echo in any public view', function () {
    $views = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views/public'))
    );

    $offenders = [];

    foreach ($views as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // `{!! !!}` is the raw echo. `@php` is banned alongside it because a
        // block of arbitrary PHP in a template is how the next raw echo gets
        // written without looking like one.
        //
        // The single deliberate exception is `@json` in the JSON-LD block,
        // which emits machine-generated JSON with JSON_HEX_* flags and never
        // tenant text verbatim — asserted separately above.
        if (str_contains($contents, '{!!') || preg_match('/@php\b/', $contents) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Unescaped output found in a public view: '.implode(', ', $offenders),
        'These templates render tenant-entered text to anonymous visitors.',
        'Use {{ }} — and if you genuinely need raw output, say why here first.',
    ]));
});
