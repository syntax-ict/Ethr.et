<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Services\CurrentTenant;

/**
 * Tenant-entered content is text, never markup.
 *
 * There is no rich-text editor anywhere in this feature and no public view
 * contains an unescaped echo outside the JSON-LD block, so the guarantee is
 * structural rather than a filter that has to be kept up to date. These tests
 * exist because "structural" is a claim, and a claim about a dozen templates
 * written by hand deserves checking against the rendered bytes.
 *
 * Two of the vectors here are specific to the builder rather than generic:
 * `icon` is interpolated into an SVG `<use href="#…">` fragment, and `link_url`
 * becomes an anchor an actual visitor clicks.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function xssTenantWithItem(array $itemAttributes, PublicSectionKind $kind = PublicSectionKind::SERVICES): void
{
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
    ]);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => $kind,
        'is_visible' => true,
    ]);

    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => $section->id,
        ...$itemAttributes,
    ]);

    app(CurrentTenant::class)->forget();
}

it('escapes a script tag typed into any text field', function (string $field) {
    // A link label only renders beside a link, so the fixture supplies a valid
    // URL as well — otherwise the label is correctly never rendered and the
    // test would pass without having exercised anything.
    xssTenantWithItem([
        $field => '<script>alert(1)</script>',
        'link_url' => 'https://example.et',
    ]);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
})->with(['title', 'body', 'link_label']);

it('escapes an event handler typed into a heading', function () {
    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'general',
    ]);
    TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
        'is_visible' => true,
        'heading' => '"><img src=x onerror=alert(1)>',
    ]);
    TenantPublicItem::factory()->create([
        'tenant_id' => $tenant->id,
        'section_id' => TenantPublicSection::query()->first()->id,
    ]);
    app(CurrentTenant::class)->forget();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x onerror=');
});

it('refuses to render a javascript link', function () {
    // Validated on the way in, and re-checked at render time — the two answer
    // different questions, and a row written before a rule existed is exactly
    // what the render-time check is for.
    xssTenantWithItem(['link_url' => 'javascript:alert(1)', 'link_label' => 'Click']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)->not->toContain('javascript:alert(1)');
});

it('refuses an icon name that is not on the allow-list', function () {
    // The value lands inside `href="#icon-…"`, so escaping alone would still
    // leave an attacker-chosen fragment in a URL. The allow-list makes it
    // unforgeable instead.
    xssTenantWithItem(['icon' => '../../evil', 'title' => 'A service']);

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('../../evil')
        ->toContain('A service');
});

it('escapes tenant content in Amharic too', function () {
    xssTenantWithItem([
        'title' => 'safe english',
        'title_am' => '<script>alert("አማርኛ")</script>',
    ]);

    $html = $this->get('http://habru.ethr.et/?lang=am')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(');
});

it('keeps a closing script tag from breaking out of the json-ld block', function () {
    xssTenantWithItem(['title' => 'x']);

    $tenant = Tenant::where('subdomain', 'habru')->firstOrFail();
    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::query()->where('tenant_id', $tenant->id)
        ->update(['headline' => '</script><script>alert(1)</script>']);
    app(CurrentTenant::class)->forget();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // JSON_HEX_TAG is what does this, and it is the one flag whose removal
    // would be invisible in every other test.
    expect($html)->not->toContain('</script><script>alert(1)');
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    expect(json_decode(trim($m[1] ?? ''), true))->toBeArray();
});

// ── No public view may contain an unescaped echo ────────────────────────────

it('contains no unescaped echo or php block in any public view', function () {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views/public'))
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        $name = basename($file);

        // `@php` is banned outright: a block of arbitrary PHP in a template is
        // how the next unescaped echo gets written without looking like one.
        $this->assertStringNotContainsString('@php', $contents, "{$name} contains a @php block");

        // `{!! !!}` likewise. The single deliberate exception is the JSON-LD
        // `@json`, whose flags are pinned by the test above.
        $this->assertStringNotContainsString('{!!', $contents, "{$name} contains an unescaped echo");
    }
});

// ── The administration side rejects rather than stores ──────────────────────

it('rejects a link that is not http or https at the api', function () {
    $tenant = createTenant(['subdomain' => 'habru']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
    ]);

    $this->postJson(
        "http://habru.ethr.et/api/v1/settings/public-page/sections/{$section->public_id}/items",
        ['title' => 'A service', 'link_url' => 'javascript:alert(1)'],
    )->assertStatus(422);
});

it('rejects an unknown icon at the api', function () {
    $tenant = createTenant(['subdomain' => 'habru']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $section = TenantPublicSection::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => PublicSectionKind::SERVICES,
    ]);

    $this->postJson(
        "http://habru.ethr.et/api/v1/settings/public-page/sections/{$section->public_id}/items",
        ['title' => 'A service', 'icon' => 'definitely-not-an-icon'],
    )->assertStatus(422)->assertJsonPath('errors.icon.0', 'That icon is not one of the available icons.');
});
