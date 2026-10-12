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
        'background' => 'image',
        'background_image_url' => 'https://cdn.acme.et/plant.jpg',
    ])->assertOk()
        ->assertJsonPath('headline', 'Welcome to Acme')
        ->assertJsonPath('background', 'image');

    $this->assertDatabaseHas('audit_log', ['action' => 'settings.landing_updated']);

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline', 'Welcome to Acme')
        ->assertJsonPath('tenant.landing.support_email', 'hr@acme.et')
        ->assertJsonPath('tenant.landing.background_image_url', 'https://cdn.acme.et/plant.jpg');
});

it('refuses what must not reach a public page', function (array $body, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'an http image' => [['background' => 'image', 'background_image_url' => 'http://x.et/a.jpg'], 'background_image_url'],
    'an image background with no image' => [['background' => 'image'], 'background_image_url'],
    'an unknown background' => [['background' => 'video'], 'background'],
    'a long headline' => [['headline' => str_repeat('a', 81)], 'headline'],
    'not an e-mail' => [['support_email' => 'hr at acme'], 'support_email'],
    'not a phone number' => [['support_phone' => 'call us'], 'support_phone'],
]);

it('filters a stored value that skipped the rules', function () {
    $tenant = createTenant();
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
        ->assertJsonPath('tenant.landing.background_image_url', null)
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
