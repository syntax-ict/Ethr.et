<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Testing\TestResponse;

/**
 * Assigning a tenant's custom domain from the platform console.
 *
 * Until this endpoint existed `custom_domain` could be set only in the
 * database, so the custom-domain branch of ResolveTenant was unreachable in
 * practice. Requests go to admin.ethr.et, the one host EnsurePlatformContext
 * serves, with APP_DOMAIN set as it is in production.
 *
 * @see docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md
 */
function domainConsoleAdmin(): User
{
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();

    $admin = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
    ]);
    test()->actingAs($admin);

    return $admin;
}

function putDomain(Tenant $tenant, mixed $domain): TestResponse
{
    return test()->putJson(
        "http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/domain",
        ['custom_domain' => $domain],
    );
}

it('assigns a custom domain, stores it normalised and audits the change', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);
    domainConsoleAdmin();

    putDomain($tenant, 'https://HR.Acme.com/')
        ->assertOk()
        ->assertJsonPath('public_id', $tenant->public_id)
        ->assertJsonPath('custom_domain', 'hr.acme.com');

    expect($tenant->fresh()->custom_domain)->toBe('hr.acme.com');
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.domain_changed']);
});

it('makes the domain resolve on the very next request, past a cached miss', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'acme', 'name' => 'Acme Ltd']);
    domainConsoleAdmin();

    // A lookup before assignment caches "no tenant here" for five minutes.
    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant', null);

    putDomain($tenant, 'hr.acme.com')->assertOk();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme Ltd');
});

it('clears the domain with null, and the old host stops resolving at once', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'acme', 'custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant.subdomain', 'acme');

    // One container serves every request in a test, so the tenant that request
    // resolved is still set; on real HTTP each request starts without one.
    app(CurrentTenant::class)->forget();

    putDomain($tenant, null)
        ->assertOk()
        ->assertJsonPath('custom_domain', null);

    expect($tenant->fresh()->custom_domain)->toBeNull();
    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant', null);
});

it('requires the field, so an empty body never silently removes a domain', function () {
    $tenant = Tenant::factory()->create(['custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    test()->putJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/domain", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');

    expect($tenant->fresh()->custom_domain)->toBe('hr.acme.com');
});

it('refuses a domain another organisation holds, however it is spelled', function () {
    Tenant::factory()->create(['custom_domain' => 'hr.acme.com']);
    $tenant = Tenant::factory()->create();
    domainConsoleAdmin();

    putDomain($tenant, 'HTTPS://HR.ACME.COM')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');
});

it('lets an organisation keep the domain it already holds', function () {
    $tenant = Tenant::factory()->create(['custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    putDomain($tenant, 'hr.acme.com')->assertOk();
});

it('refuses a host the deployment already owns', function (string $domain) {
    $tenant = Tenant::factory()->create();
    domainConsoleAdmin();

    putDomain($tenant, $domain)
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_domain.0', __('validation.custom_domain_reserved', ['root' => 'ethr.et']));
})->with(['ethr.et', 'www.ethr.et', 'admin.ethr.et', 'habru.ethr.et', 'HTTPS://Acme.ETHR.et/']);

it('refuses what is not a hostname', function (string $domain) {
    $tenant = Tenant::factory()->create();
    domainConsoleAdmin();

    putDomain($tenant, $domain)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');
})->with(['acme', 'hr acme.com', '-hr.acme.com', 'hr.acme.c', 'hr..acme.com', 'hr.acme.123']);

it('reports the custom domain on the tenant detail and in the list', function () {
    $tenant = Tenant::factory()->create(['custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    test()->getJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}")
        ->assertOk()
        ->assertJsonPath('custom_domain', 'hr.acme.com');

    $row = collect(test()->getJson('http://admin.ethr.et/api/v1/admin/tenants')->assertOk()->json('data'))
        ->firstWhere('public_id', $tenant->public_id);
    expect($row['custom_domain'])->toBe('hr.acme.com');
});

it('refuses a tenant admin', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson("http://acme.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/domain", ['custom_domain' => 'hr.acme.com'])
        ->assertForbidden();

    expect($tenant->fresh()->custom_domain)->toBeNull();
});
