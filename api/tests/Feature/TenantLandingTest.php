<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SsoSetting;
use App\Models\Tenant;

/**
 * The organisation's sign-in page settings (ethr.et/{slug}): its own words,
 * help contacts and background, and which ways in it offers. All of it is
 * shown before sign-in, so it is validated on the way in and filtered on the
 * way out.
 */
function landingUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('saves the sign-in page and shows it before sign-in', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'headline' => 'Welcome to Acme',
        'message' => 'Check in when you arrive at the plant.',
        'support_email' => 'hr@acme.et',
        'support_phone' => '+251 11 555 0100',
        'background' => 'pattern',
    ])->assertOk()
        ->assertJsonPath('headline', 'Welcome to Acme')
        ->assertJsonPath('background', 'pattern');

    $this->assertDatabaseHas('audit_log', ['action' => 'settings.landing_updated']);

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline', 'Welcome to Acme')
        ->assertJsonPath('tenant.landing.support_email', 'hr@acme.et')
        ->assertJsonPath('tenant.landing.background', 'pattern')
        ->assertJsonMissingPath('tenant.landing.background_image_url');
});

it('keeps what a save does not mention, section toggles included', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => [
        'headline' => 'Old headline',
        'support_email' => 'hr@acme.et',
        'background' => 'gradient',
        'sections' => ['announcements' => false, 'vacancies' => true, 'holidays' => true],
    ]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // The form sends only the fields it edits. Before the merge fix this
    // write reset every absent key: the toggles went back to their defaults
    // and the support email vanished.
    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'headline' => 'New headline',
        'background' => 'brand',
    ])->assertOk();

    $landing = $tenant->fresh()->landing;
    expect($landing['headline'])->toBe('New headline')
        ->and($landing['support_email'])->toBe('hr@acme.et')
        ->and($landing['sections'])->toBe(['announcements' => false, 'vacancies' => true, 'holidays' => true]);
});

it('merges section toggles per key', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => [
        'background' => 'brand',
        'sections' => ['announcements' => false, 'holidays' => true],
    ]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'sections' => ['vacancies' => false],
    ])->assertOk();

    expect($tenant->fresh()->landing['sections'])
        ->toBe(['announcements' => false, 'holidays' => true, 'vacancies' => false]);
});

it('refuses what must not reach a public page', function (array $body, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    // The photo background is the uploaded cover; TenantBrandAssetTest
    // chooses it after an upload. Without one there is nothing to draw.
    'a photo background with no cover uploaded' => [['background' => 'image'], 'background'],
    'an unknown background' => [['background' => 'video'], 'background'],
    'a long headline' => [['headline' => str_repeat('a', 81)], 'headline'],
    'not an e-mail' => [['support_email' => 'hr at acme'], 'support_email'],
    'not a phone number' => [['support_phone' => 'call us'], 'support_phone'],
]);

it('filters a stored value that skipped the rules', function () {
    $tenant = createTenant();
    // A row from before 2026-10-14 may still carry a typed photo URL: it is
    // not read, and a photo background with no cover is the brand colour.
    $tenant->update(['landing' => [
        'headline' => str_repeat('x', 200),
        'background' => 'image',
        'background_image_url' => 'javascript:alert(1)',
        'support_email' => 'nope',
    ]]);

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline', str_repeat('x', 80))
        ->assertJsonPath('tenant.landing.background', 'brand')
        ->assertJsonMissingPath('tenant.landing.background_image_url')
        ->assertJsonPath('tenant.landing.support_email', null);
});

it('offers SSO on the sign-in page only once the organisation configured it', function () {
    $tenant = createTenant();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertJsonPath('tenant.sign_in.sso', false);

    SsoSetting::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.acme.et',
        'idp_sso_url' => 'https://idp.acme.et/sso',
    ]);

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertJsonPath('tenant.sign_in.sso', true);
});

it('lets only an administrator change the sign-in page', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand'])
        ->assertForbidden();
});
