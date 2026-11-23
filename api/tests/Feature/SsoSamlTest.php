<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SsoSetting;
use App\Services\Sso\SamlProvider;
use App\Services\Sso\SsoProviderInterface;
use Illuminate\Support\Facades\DB;

// ──────────────────────────── SSO configuration ────────────────────────

test('sso settings default to disabled', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = $this->getJson('/api/v1/settings');

    $response->assertOk();
    expect($response->json('sso.is_enabled'))->toBeFalse();
    expect($response->json('sso.provider'))->toBe('saml');
});

test('tenant admin can update sso settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = $this->putJson('/api/v1/settings/sso', [
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com/entity',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
        'default_role' => 'employee',
        'auto_provision' => true,
    ]);

    $response->assertOk();
    expect($response->json('sso.is_enabled'))->toBeTrue();
    expect($response->json('sso.idp_entity_id'))->toBe('https://idp.example.com/entity');
    expect($response->json('sso.auto_provision'))->toBeTrue();
    expect($response->json('sso.configured'))->toBeTrue();
    expect($response->json('sso.has_certificate'))->toBeTrue();
});

test('sso cannot be switched on without the identity provider details', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('/api/v1/settings/sso', ['is_enabled' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idp_entity_id', 'idp_sso_url', 'idp_certificate']);

    $this->putJson('/api/v1/settings/sso', [
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com/entity',
        'idp_sso_url' => 'https://idp.example.com/sso',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idp_certificate'])
        ->assertJsonMissingValidationErrors(['idp_entity_id', 'idp_sso_url']);

    expect(SsoSetting::query()->where('is_enabled', true)->exists())->toBeFalse();
});

test('sso can be switched on with the certificate already stored', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => false,
        'idp_entity_id' => 'https://idp.example.com/entity',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $this->putJson('/api/v1/settings/sso', ['is_enabled' => true])
        ->assertOk()
        ->assertJsonPath('sso.is_enabled', true)
        ->assertJsonPath('sso.configured', true);
});

test('sso that is on cannot lose its certificate or its sign-in url', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com/entity',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $this->putJson('/api/v1/settings/sso', ['idp_sso_url' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idp_sso_url']);

    $this->putJson('/api/v1/settings/sso', ['idp_certificate' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idp_certificate']);

    $this->putJson('/api/v1/settings/sso', ['default_role' => 'supervisor'])->assertOk();

    $this->putJson('/api/v1/settings/sso', ['is_enabled' => false, 'idp_certificate' => null])->assertOk();

    $stored = SsoSetting::query()->first();
    expect($stored->is_enabled)->toBeFalse()
        ->and($stored->getRawOriginal('idp_certificate'))->toBeNull();
});

test('sso cannot be switched on with a 1 or "1" instead of true', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    foreach ([1, '1'] as $on) {
        $this->putJson('/api/v1/settings/sso', ['is_enabled' => $on])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idp_entity_id', 'idp_sso_url', 'idp_certificate']);
    }
});

test('a rule failure does not hide the missing identity provider details', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('/api/v1/settings/sso', ['is_enabled' => true, 'idp_sso_url' => 'not-a-url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idp_sso_url']);
});

test('the settings page says an enabled but incomplete sso is not configured', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
    ]);

    $this->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('sso.is_enabled', true)
        ->assertJsonPath('sso.configured', false)
        ->assertJsonPath('sso.has_certificate', false);
});

test('employee cannot update sso settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $response = $this->putJson('/api/v1/settings/sso', [
        'is_enabled' => true,
    ]);

    $response->assertForbidden();
});

test('sso settings stores idp certificate encrypted', function () {
    $tenant = createTenant();

    $cert = "-----BEGIN CERTIFICATE-----\nMIIDpDCCAoygAwIBAgIGAX...\n-----END CERTIFICATE-----";

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => $cert,
    ]);

    $sso = SsoSetting::where('tenant_id', $tenant->id)->first();
    expect($sso->idp_certificate)->toBe($cert);

    $raw = DB::table('sso_settings')
        ->where('tenant_id', $tenant->id)
        ->value('idp_certificate');
    expect($raw)->not->toBe($cert);
});

// ──────────────────────────── SSO provider ────────────────────────────

test('saml provider is bound to interface', function () {
    $provider = app(SsoProviderInterface::class);
    expect($provider)->toBeInstanceOf(SamlProvider::class);
});

test('isConfigured returns false when no sso settings exist', function () {
    $tenant = createTenant();
    $provider = app(SsoProviderInterface::class);
    expect($provider->isConfigured($tenant))->toBeFalse();
});

test('isConfigured returns false when disabled', function () {
    $tenant = createTenant();

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => false,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $provider = app(SsoProviderInterface::class);
    expect($provider->isConfigured($tenant))->toBeFalse();
});

test('isConfigured returns true when fully configured', function () {
    $tenant = createTenant();

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $provider = app(SsoProviderInterface::class);
    expect($provider->isConfigured($tenant))->toBeTrue();
});

test('metadata endpoint returns xml', function () {
    $tenant = createTenant();

    $response = $this->get("/api/v1/sso/saml/{$tenant->subdomain}/metadata");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml');
    expect($response->getContent())->toContain('EntityDescriptor');
    expect($response->getContent())->toContain('SPSSODescriptor');
});

test('initiate returns error when sso not configured', function () {
    $tenant = createTenant();

    $response = $this->getJson("/api/v1/sso/saml/{$tenant->subdomain}/initiate");

    $response->assertStatus(422);
    expect($response->json('type'))->toBe('https://ethr.et/errors/sso-not-configured');
});

test('initiate returns redirect url when configured', function () {
    $tenant = createTenant();

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $response = $this->getJson("/api/v1/sso/saml/{$tenant->subdomain}/initiate");

    $response->assertOk();
    expect($response->json('redirect_url'))->toStartWith('https://idp.example.com/sso?SAMLRequest=');
});

test('acs callback rejects invalid saml response', function () {
    $tenant = createTenant();

    SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.example.com',
        'idp_sso_url' => 'https://idp.example.com/sso',
        'idp_certificate' => 'cert-data',
    ]);

    $response = $this->postJson("/api/v1/sso/saml/{$tenant->subdomain}/acs", [
        'SAMLResponse' => base64_encode('<invalid/>'),
    ]);

    // The browser is sent back to the app with the reason, not shown JSON
    // (audit N60).
    $response->assertStatus(303);
    expect($response->headers->get('Location'))->toContain('/login/sso?')
        ->toContain('error=failed');
});

test('sso setting model belongs to tenant', function () {
    $tenant = createTenant();

    $sso = SsoSetting::create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => false,
    ]);

    expect($sso->tenant->id)->toBe($tenant->id);
});
