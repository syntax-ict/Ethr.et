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
        'default_role' => 'employee',
        'auto_provision' => true,
    ]);

    $response->assertOk();
    expect($response->json('sso.is_enabled'))->toBeTrue();
    expect($response->json('sso.idp_entity_id'))->toBe('https://idp.example.com/entity');
    expect($response->json('sso.auto_provision'))->toBeTrue();
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

    $response->assertStatus(401);
    expect($response->json('type'))->toBe('https://ethr.et/errors/sso-failed');
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
