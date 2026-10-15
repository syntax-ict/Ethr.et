<?php

declare(strict_types=1);

use App\Enums\TenantAddressMode;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;

/**
 * Single-tenant / on-prem mode: ETHR_MODE=single (gap plan Wave 3 #9).
 *
 * One installation serves exactly one organisation. ResolveTenant resolves it
 * on every request with no host, slug or header; the host selectors step aside
 * entirely (an on-prem hostname carries no tenant meaning); self-service
 * registration closes once the organisation exists; mail links point at plain
 * /login. The explicit selectors still win — X-Tenant and the login form's
 * organisation field — so EnsureUserBelongsToTenant decides access exactly as
 * on the SaaS deployment. docs/ON-PREM.md is the install guide.
 */
function singleInstallOrg(string $slug = 'onprem', array $attributes = []): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug] + $attributes);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $admin];
}

function asSingleInstall(?string $slug = 'onprem'): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.domain' => 'ethr.corp.local',
        'ethr.mode' => 'single',
        'ethr.single_tenant' => $slug,
    ]);
    app(CurrentTenant::class)->forget();
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('defaults to the multi-tenant mode', function () {
    expect(config('ethr.mode'))->toBe('multi');
});

// ── Resolution without a selector ───────────────────────────────────────────

it('serves a member their organisation with no host, slug or header', function () {
    [, $admin] = singleInstallOrg();
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    asSingleInstall();

    test()->withToken($token)
        ->getJson('http://ethr.corp.local/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('tells the sign-in page which organisation this install serves', function () {
    singleInstallOrg();
    asSingleInstall();

    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'onprem');
});

it('resolves on a bare IP, which an intranet install answers to', function () {
    singleInstallOrg();
    asSingleInstall();

    test()->getJson('http://192.168.4.20/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'onprem');
});

it('reads no tenant out of the hostname in single mode', function () {
    singleInstallOrg();
    asSingleInstall();

    // In multi mode this host would try to resolve "other" and answer 404.
    // On a single install hosts carry no tenant meaning.
    test()->getJson('http://other.ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'onprem');
});

it('keeps the platform host tenant-free', function () {
    singleInstallOrg();
    asSingleInstall();

    test()->getJson('http://admin.ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

it('lets an explicit X-Tenant header win over the fallback', function () {
    singleInstallOrg();
    singleInstallOrg('second');
    asSingleInstall();

    test()->withHeaders(['X-Tenant' => 'second'])
        ->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'second');
});

// ── ETHR_SINGLE_TENANT unset: the sole organisation, or nothing ─────────────

it('resolves a sole organisation when no slug is configured', function () {
    singleInstallOrg();
    asSingleInstall(null);

    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'onprem');
});

it('refuses to guess between two organisations when no slug is configured', function () {
    singleInstallOrg();
    singleInstallOrg('second');
    asSingleInstall(null);

    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

// ── Misconfiguration fails loudly, not quietly ──────────────────────────────

it('answers 404 on every request when the configured slug names no organisation', function () {
    singleInstallOrg();
    asSingleInstall('typo');

    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertNotFound()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-not-found');
});

it('reports an inactive organisation rather than serving empty pages', function () {
    Tenant::factory()->suspended()->create(['subdomain' => 'onprem']);
    asSingleInstall();

    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-inactive');
});

// ── Registration: open for the first organisation, closed after ─────────────

it('closes registration once the organisation exists', function () {
    singleInstallOrg();
    asSingleInstall();

    test()->postJson('http://ethr.corp.local/api/v1/auth/register', [])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/registration-closed');
});

it('closes the subdomain availability check with it', function () {
    singleInstallOrg();
    asSingleInstall();

    test()->getJson('http://ethr.corp.local/api/v1/register/check-subdomain?subdomain=acme')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/registration-closed');
});

it('leaves a setup window: a fresh install registers its one organisation', function () {
    asSingleInstall(null);

    test()->postJson('http://ethr.corp.local/api/v1/auth/register', [
        'organization_name' => 'Mersa Manufacturing',
        'subdomain' => 'mersa',
        'admin_name' => 'Admin',
        'admin_email' => 'admin@mersa.example',
        'password' => 'Str0ng-passw0rd',
        'password_confirmation' => 'Str0ng-passw0rd',
    ])->assertCreated();

    // The sole-organisation cache was busted by registerTenant(), so the new
    // organisation resolves immediately rather than after the cache TTL.
    test()->getJson('http://ethr.corp.local/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'mersa');
});

it('keeps registration open on the SaaS deployment, tenants or not', function () {
    singleInstallOrg();
    app()->detectEnvironment(fn () => 'production');
    config(['app.domain' => 'ethr.et', 'ethr.mode' => 'multi']);

    test()->getJson('http://ethr.et/api/v1/register/check-subdomain?subdomain=acme')
        ->assertOk()
        ->assertJsonPath('available', true);
});

// ── Mail links ──────────────────────────────────────────────────────────────

it('mails plain /login links on a single install', function () {
    [$tenant] = singleInstallOrg();
    config(['ethr.mode' => 'single']);

    $frontend = rtrim((string) config('app.frontend_url'), '/');

    expect(FrontendUrl::at($tenant, 'login'))->toBe($frontend.'/login')
        ->and(FrontendUrl::canonicalOrigin($tenant))->toBeNull();
});

it('gives the one organisation no per-tenant origin even when its row asks for one', function () {
    config(['tenancy.subdomains' => true, 'app.domain' => 'ethr.corp.local']);
    [$tenant] = singleInstallOrg('onprem', ['address_mode' => TenantAddressMode::SUBDOMAIN]);

    config(['ethr.mode' => 'multi']);
    expect(FrontendUrl::canonicalOrigin($tenant))->not->toBeNull();

    config(['ethr.mode' => 'single']);
    expect(FrontendUrl::canonicalOrigin($tenant))->toBeNull();
});
