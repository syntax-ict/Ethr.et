<?php

declare(strict_types=1);

use App\Enums\PublicPagePreset;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use App\Services\Onboarding\IndustryCatalog;

/**
 * Every preset is wired everywhere a preset has to be wired.
 *
 * This is the least interesting file to read and the most useful one to have.
 * Adding a ninth preset means touching an enum, a stylesheet and a structured
 * data map, and the failure mode of forgetting one is silent: a tenant gets a
 * page with no skin, or a crawler gets `Organization` where the whole point was
 * to say `Hospital`. Nothing else in the suite notices, because every other
 * test names the presets it happens to care about.
 *
 * So these iterate `PublicPagePreset::cases()` rather than listing values. A
 * new case is covered the moment it exists, and the failure names which case
 * and which wiring point.
 */
it('gives every preset a body class with a matching stylesheet rule', function () {
    $css = file_get_contents(public_path('assets/ethr-public.css'));

    foreach (PublicPagePreset::cases() as $preset) {
        $class = $preset->bodyClass();

        // assertStringContainsString rather than expect()->toContain():
        // Pest treats extra arguments to toContain() as further needles, so a
        // "message" silently becomes a second string to search for and the
        // failure output is nonsense. PHPUnit's assertion takes a real message.
        $this->assertStringContainsString(
            '.'.$class,
            $css,
            "preset {$preset->value} has body class {$class} but no rule in ethr-public.css"
        );
    }
});

it('gives every preset a schema.org type', function () {
    foreach (PublicPagePreset::cases() as $preset) {
        // Not merely non-empty: a type that is not a real schema.org identifier
        // is worse than none, because a crawler acts on it.
        $this->assertMatchesRegularExpression(
            '/^[A-Z][A-Za-z]+$/',
            $preset->schemaType(),
            "preset {$preset->value} has an implausible schema type"
        );
    }
});

it('reserves verification for the one preset that makes a claim about the tenant', function () {
    $verified = array_values(array_filter(
        PublicPagePreset::cases(),
        static fn (PublicPagePreset $preset): bool => $preset->requiresVerification(),
    ));

    // If a second preset ever needs gating this should be a deliberate edit
    // here, not a quiet change in the enum. And if `government` ever stops
    // being gated, this fails loudly rather than letting the control lapse.
    expect($verified)->toBe([PublicPagePreset::GOVERNMENT]);
});

it('matches the base templates onboarding already maintains', function () {
    $catalog = app(IndustryCatalog::class);

    $bases = [];
    foreach ($catalog->keys() as $key) {
        $industry = $catalog->find($key);
        $bases[$industry['base']] = true;
    }

    $presets = array_map(
        static fn (PublicPagePreset $preset): string => $preset->value,
        PublicPagePreset::cases(),
    );

    // The two lists are maintained in different files for different reasons —
    // one provisions departments, the other styles a public page — and they
    // drift the moment nobody is checking. A base with no preset leaves those
    // tenants unstyled; the reverse leaves a preset nothing can select.
    $missing = array_diff(array_keys($bases), $presets);

    $this->assertSame(
        [],
        array_values($missing),
        'industry bases with no matching preset: '.implode(', ', $missing)
    );
});

it('renders a real page for every preset', function (string $value) {
    config(['app.domain' => 'ethr.et']);

    $tenant = createTenant(['subdomain' => 'habru', 'name' => 'Habru']);
    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => $value,
    ]);
    app(CurrentTenant::class)->forget();

    // The end-to-end version of the stylesheet check: a preset that resolves,
    // skins and renders without throwing. Cheap, and it catches a template
    // that only exists for some of them.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('preset--'.$value, escape: false);
})->with(array_map(
    static fn (PublicPagePreset $case): string => $case->value,
    PublicPagePreset::cases(),
));
