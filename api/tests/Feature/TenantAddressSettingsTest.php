<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Enums\TenantAddressMode;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\CustomDomainVerifier;

/**
 * An organisation choosing its own address from Settings, including
 * assigning and verifying its own domain — which until 2026-10-11 only the
 * platform console could do, on the organisation's behalf.
 */
function addressSettingsTenant(bool $planIncludesDomain = true, array $attributes = []): Tenant
{
    $tenant = createTenant($attributes);
    $features = ['attendance', 'leave', 'employee_management'];
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => Plan::factory()->create([
            'features' => $planIncludesDomain ? [...$features, 'custom_domain'] : $features,
        ])->id,
    ]);

    return $tenant->refresh();
}

function addressSettingsUrl(Tenant $tenant, string $path = ''): string
{
    return 'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/address'.$path;
}

function fakeAddressDns(array $txt = [], array $cname = []): void
{
    app()->instance(DnsResolver::class, new class($txt, $cname) implements DnsResolver
    {
        public function __construct(private array $txtRecords, private array $cnameRecords) {}

        public function txt(string $name): array
        {
            return $this->txtRecords[$name] ?? [];
        }

        public function cname(string $name): ?string
        {
            return $this->cnameRecords[$name] ?? null;
        }
    });
}

beforeEach(function () {
    // Links take the frontend's scheme and port; a fixed one keeps the
    // expected addresses below independent of the local .env.
    config(['app.domain' => 'ethr.test', 'app.frontend_url' => 'http://ethr.test', 'tenancy.custom_domain_target' => null]);
});

it('shows the organisation its address, what it can choose and what the plan allows', function () {
    $tenant = addressSettingsTenant(planIncludesDomain: false);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('address.mode', 'path')
        ->assertJsonPath('address.effective_mode', 'path')
        ->assertJsonPath('address.canonical_url', null)
        ->assertJsonPath('address.subdomain', $tenant->subdomain)
        ->assertJsonPath('address.subdomains_served', false)
        ->assertJsonPath('address.custom_domain', null)
        ->assertJsonPath('address.custom_domain_status', null)
        ->assertJsonPath('address.custom_domain_allowed', false)
        ->assertJsonPath('address.custom_domain_dns', null);
});

it('lets a tenant admin choose the subdomain, and keeps the choice while the host cannot serve it yet', function () {
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'subdomain'])
        ->assertOk()
        ->assertJsonPath('address.mode', 'subdomain')
        ->assertJsonPath('address.effective_mode', 'path');

    expect($tenant->fresh()->address_mode)->toBe(TenantAddressMode::SUBDOMAIN);
    test()->assertDatabaseHas('audit_log', ['action' => 'settings.address_updated']);

    config(['tenancy.subdomains' => true]);

    test()->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings')
        ->assertJsonPath('address.effective_mode', 'subdomain')
        ->assertJsonPath('address.subdomains_served', true);
});

it('lets a tenant admin assign its own domain, pending until verified, when the plan includes the add-on', function () {
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->putJson(addressSettingsUrl($tenant), ['mode' => 'custom_domain', 'custom_domain' => 'HR.Acme.com'])
        ->assertOk()
        ->assertJsonPath('address.mode', 'custom_domain')
        ->assertJsonPath('address.effective_mode', 'path')
        ->assertJsonPath('address.custom_domain', 'hr.acme.com')
        ->assertJsonPath('address.custom_domain_status', 'pending')
        ->assertJsonPath('address.custom_domain_dns.txt_name', '_ethr-verification.hr.acme.com')
        ->assertJsonPath('address.custom_domain_dns.cname_name', 'hr.acme.com');

    expect($response->json('address.custom_domain_dns.txt_value'))->toStartWith(CustomDomainVerifier::TXT_VALUE_PREFIX)
        ->and($tenant->fresh()->custom_domain_verified_at)->toBeNull();

    test()->assertDatabaseHas('audit_log', ['action' => 'settings.domain_changed'])
        ->assertDatabaseHas('audit_log', ['action' => 'settings.address_updated']);
});

it('refuses a domain when the plan does not include the add-on, and says so on the field', function () {
    $tenant = addressSettingsTenant(planIncludesDomain: false);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'custom_domain', 'custom_domain' => 'hr.acme.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['custom_domain']);

    expect($tenant->fresh()->custom_domain)->toBeNull()
        ->and($tenant->fresh()->address_mode)->toBeNull();
});

it('refuses a host under the platform\'s own domain, and one another organisation holds', function () {
    $other = addressSettingsTenant(attributes: ['custom_domain' => 'hr.taken.com']);
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'custom_domain', 'custom_domain' => 'acme.ethr.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['custom_domain']);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'custom_domain', 'custom_domain' => $other->custom_domain])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['custom_domain']);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'elsewhere'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);
});

it('clears the domain with null, which needs no add-on', function () {
    $tenant = addressSettingsTenant(planIncludesDomain: false, attributes: ['custom_domain' => 'hr.acme.com']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'path', 'custom_domain' => null])
        ->assertOk()
        ->assertJsonPath('address.custom_domain', null)
        ->assertJsonPath('address.mode', 'path');
});

it('verifies the domain once both DNS records are in place, and names each that is not', function () {
    $tenant = addressSettingsTenant(attributes: ['custom_domain' => 'hr.acme.com', 'address_mode' => TenantAddressMode::CUSTOM_DOMAIN]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $token = $tenant->fresh()->custom_domain_token;

    fakeAddressDns();
    test()->postJson(addressSettingsUrl($tenant, '/verify'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['txt', 'cname']);

    fakeAddressDns(
        txt: ['_ethr-verification.hr.acme.com' => [CustomDomainVerifier::txtValue((string) $token)]],
        cname: ['hr.acme.com' => 'ethr.test'],
    );
    test()->postJson(addressSettingsUrl($tenant, '/verify'))
        ->assertOk()
        ->assertJsonPath('address.custom_domain_status', 'verified')
        ->assertJsonPath('address.effective_mode', 'custom_domain')
        ->assertJsonPath('address.canonical_url', 'http://hr.acme.com');

    test()->assertDatabaseHas('audit_log', ['action' => 'settings.domain_verified']);
});

it('refuses to verify when no domain is assigned', function () {
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(addressSettingsUrl($tenant, '/verify'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['custom_domain']);
});

it('is a settings.manage action: an employee can neither choose nor verify', function () {
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(addressSettingsUrl($tenant), ['mode' => 'subdomain'])->assertForbidden();
    test()->postJson(addressSettingsUrl($tenant, '/verify'))->assertForbidden();

    expect($tenant->fresh()->address_mode)->toBeNull();
});

// ── The platform console, on the organisation's behalf ──────────────────────

it('lets the platform console set the mode, reports what is effective, and audits it', function () {
    $tenant = addressSettingsTenant();
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();
    test()->actingAs(User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
    ]));

    test()->putJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/address-mode", ['mode' => 'subdomain'])
        ->assertOk()
        ->assertJsonPath('public_id', $tenant->public_id)
        ->assertJsonPath('address_mode', 'subdomain')
        ->assertJsonPath('address_mode_effective', 'path')
        ->assertJsonPath('canonical_url', null);

    test()->getJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}")
        ->assertOk()
        ->assertJsonPath('address_mode', 'subdomain')
        ->assertJsonPath('address_mode_effective', 'path');

    expect($tenant->fresh()->address_mode)->toBe(TenantAddressMode::SUBDOMAIN);
    test()->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.address_mode_changed']);
});

it('refuses a tenant admin on the console route', function () {
    $tenant = addressSettingsTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // The console does not exist for a tenant account anywhere: off the
    // platform host EnsurePlatformContext answers 404, and on it a
    // non-platform account is hidden the same way rather than told "no".
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/address-mode", ['mode' => 'path'])
        ->assertNotFound();
    test()->putJson("http://admin.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/address-mode", ['mode' => 'path'])
        ->assertNotFound();

    expect($tenant->fresh()->address_mode)->toBeNull();
});
