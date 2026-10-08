<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Testing\TestResponse;

/**
 * Assigning and verifying a tenant's custom domain from the platform console.
 *
 * Until this endpoint existed `custom_domain` could be set only in the
 * database, so the custom-domain branch of ResolveTenant was unreachable in
 * practice. Requests go to admin.ethr.et, the one host EnsurePlatformContext
 * serves, with APP_DOMAIN set as it is in production.
 *
 * Since 2026-10-08 a custom domain is the Enterprise tier of an organisation's
 * address: assigning one needs the `custom_domain` plan feature, and the
 * domain stays pending, resolving nothing, until Verify finds its TXT token
 * and its CNAME to the platform.
 *
 * @see docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md
 */
function domainConsoleAdmin(): User
{
    config(['app.domain' => 'ethr.et', 'tenancy.custom_domain_target' => null]);
    app(CurrentTenant::class)->forget();

    $admin = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
    ]);
    test()->actingAs($admin);

    return $admin;
}

/** A tenant on a plan that includes (or omits) the custom-domain add-on. */
function domainTenant(array $attributes = [], bool $planIncludesDomain = true): Tenant
{
    $tenant = Tenant::factory()->create($attributes);
    $features = ['attendance', 'leave', 'employee_management'];

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => Plan::factory()->create([
            'features' => $planIncludesDomain ? [...$features, 'custom_domain'] : $features,
        ])->id,
    ]);

    return $tenant->refresh();
}

/**
 * Answers DNS from the given records instead of the network.
 *
 * @param  array<string, list<string>>  $txt
 * @param  array<string, string>  $cname
 */
function fakeDns(array $txt = [], array $cname = []): void
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

/** The records an organisation would publish for its current token. */
function publishDnsFor(Tenant $tenant): void
{
    $tenant->refresh();
    fakeDns(
        txt: ["_ethr-verification.{$tenant->custom_domain}" => ["ethr-verification={$tenant->custom_domain_token}"]],
        cname: [$tenant->custom_domain => 'ethr.et'],
    );
}

function putDomain(Tenant $tenant, mixed $domain): TestResponse
{
    return test()->putJson(
        "http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/domain",
        ['custom_domain' => $domain],
    );
}

function verifyDomain(Tenant $tenant): TestResponse
{
    return test()->postJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/domain/verify");
}

// ── Assignment ──────────────────────────────────────────────────────────────

it('assigns a custom domain, stores it normalised and audits the change', function () {
    $tenant = domainTenant(['subdomain' => 'acme']);
    domainConsoleAdmin();

    putDomain($tenant, 'https://HR.Acme.com/')
        ->assertOk()
        ->assertJsonPath('public_id', $tenant->public_id)
        ->assertJsonPath('custom_domain', 'hr.acme.com');

    expect($tenant->fresh()->custom_domain)->toBe('hr.acme.com');
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.domain_changed']);
});

it('stores a new domain pending, with the DNS records that will verify it', function () {
    $tenant = domainTenant(['subdomain' => 'acme']);
    domainConsoleAdmin();

    $response = putDomain($tenant, 'hr.acme.com')
        ->assertOk()
        ->assertJsonPath('custom_domain_status', 'pending')
        ->assertJsonPath('custom_domain_dns.txt_name', '_ethr-verification.hr.acme.com')
        ->assertJsonPath('custom_domain_dns.cname_name', 'hr.acme.com')
        ->assertJsonPath('custom_domain_dns.cname_target', 'ethr.et');

    $token = $tenant->fresh()->custom_domain_token;
    expect($token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($response->json('custom_domain_dns.txt_value'))->toBe("ethr-verification={$token}")
        ->and($tenant->fresh()->custom_domain_verified_at)->toBeNull();
});

it('names a dedicated CNAME target when the platform configures one', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    config(['tenancy.custom_domain_target' => 'Domains.ETHR.et.']);

    putDomain($tenant, 'hr.acme.com')
        ->assertOk()
        ->assertJsonPath('custom_domain_dns.cname_target', 'domains.ethr.et');
});

it('does not resolve a domain that is assigned but not yet verified', function () {
    $tenant = domainTenant(['subdomain' => 'acme', 'name' => 'Acme Ltd']);
    domainConsoleAdmin();

    putDomain($tenant, 'hr.acme.com')->assertOk();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

it('clears the domain with null, and the old host stops resolving at once', function () {
    $tenant = domainTenant([
        'subdomain' => 'acme',
        'custom_domain' => 'hr.acme.com',
        'custom_domain_verified_at' => now(),
    ]);
    domainConsoleAdmin();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant.subdomain', 'acme');

    // One container serves every request in a test, so the tenant that request
    // resolved is still set; on real HTTP each request starts without one.
    app(CurrentTenant::class)->forget();

    putDomain($tenant, null)
        ->assertOk()
        ->assertJsonPath('custom_domain', null)
        ->assertJsonPath('custom_domain_status', null)
        ->assertJsonPath('custom_domain_dns', null);

    expect($tenant->fresh()->custom_domain)->toBeNull();
    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant', null);
});

it('requires the field, so an empty body never silently removes a domain', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    test()->putJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}/domain", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');

    expect($tenant->fresh()->custom_domain)->toBe('hr.acme.com');
});

it('refuses a domain another organisation holds, however it is spelled', function () {
    domainTenant(['custom_domain' => 'hr.acme.com']);
    $tenant = domainTenant();
    domainConsoleAdmin();

    putDomain($tenant, 'HTTPS://HR.ACME.COM')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');
});

it('lets an organisation keep the domain it already holds, verification and all', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com', 'custom_domain_verified_at' => now()]);
    domainConsoleAdmin();

    putDomain($tenant, 'hr.acme.com')
        ->assertOk()
        ->assertJsonPath('custom_domain_status', 'verified');
});

it('refuses a host the deployment already owns', function (string $domain) {
    $tenant = domainTenant();
    domainConsoleAdmin();

    putDomain($tenant, $domain)
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_domain.0', __('validation.custom_domain_reserved', ['root' => 'ethr.et']));
})->with(['ethr.et', 'www.ethr.et', 'admin.ethr.et', 'habru.ethr.et', 'HTTPS://Acme.ETHR.et/']);

it('refuses what is not a hostname', function (string $domain) {
    $tenant = domainTenant();
    domainConsoleAdmin();

    putDomain($tenant, $domain)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('custom_domain');
})->with(['acme', 'hr acme.com', '-hr.acme.com', 'hr.acme.c', 'hr..acme.com', 'hr.acme.123']);

// ── The plan gate ───────────────────────────────────────────────────────────

it('refuses a domain when the organisation\'s plan does not include one', function () {
    $tenant = domainTenant(planIncludesDomain: false);
    domainConsoleAdmin();

    putDomain($tenant, 'hr.acme.com')
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_domain.0', __('validation.custom_domain_not_in_plan'));

    expect($tenant->fresh()->custom_domain)->toBeNull();
});

it('refuses a domain to a trial or an organisation with no plan, which pay for no add-on', function (string $case) {
    $tenant = $case === 'trial'
        ? Tenant::factory()->trial()->create()
        : Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);
    domainConsoleAdmin();

    putDomain($tenant, 'hr.acme.com')
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_domain.0', __('validation.custom_domain_not_in_plan'));
})->with(['trial', 'no plan']);

it('always lets a domain be cleared, whatever the plan', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com'], planIncludesDomain: false);
    domainConsoleAdmin();

    putDomain($tenant, null)->assertOk();

    expect($tenant->fresh()->custom_domain)->toBeNull();
});

// ── Verify ──────────────────────────────────────────────────────────────────

it('verifies a domain whose TXT token and CNAME check out, and it resolves on the next request', function () {
    $tenant = domainTenant(['subdomain' => 'acme', 'name' => 'Acme Ltd']);
    domainConsoleAdmin();

    // A lookup before verification caches "no tenant here" for five minutes.
    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant', null);

    putDomain($tenant, 'hr.acme.com')->assertOk();
    publishDnsFor($tenant);

    verifyDomain($tenant)
        ->assertOk()
        ->assertJsonPath('custom_domain', 'hr.acme.com')
        ->assertJsonPath('custom_domain_status', 'verified');

    expect($tenant->fresh()->custom_domain_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.domain_verified']);

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme Ltd');
});

it('accepts a CNAME to the dedicated target when one is configured', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    config(['tenancy.custom_domain_target' => 'domains.ethr.et']);

    putDomain($tenant, 'hr.acme.com')->assertOk();
    $token = $tenant->fresh()->custom_domain_token;
    fakeDns(
        txt: ['_ethr-verification.hr.acme.com' => ["ethr-verification={$token}"]],
        cname: ['hr.acme.com' => 'domains.ethr.et'],
    );

    verifyDomain($tenant)->assertOk()->assertJsonPath('custom_domain_status', 'verified');
});

it('stays pending and names the TXT record when the token is missing', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    putDomain($tenant, 'hr.acme.com')->assertOk();
    fakeDns(cname: ['hr.acme.com' => 'ethr.et']);

    verifyDomain($tenant)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('txt')
        ->assertJsonMissingValidationErrors('cname');

    expect($tenant->fresh()->custom_domain_verified_at)->toBeNull();
});

it('stays pending and names the CNAME when the domain does not point here', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    putDomain($tenant, 'hr.acme.com')->assertOk();
    $token = $tenant->fresh()->custom_domain_token;
    fakeDns(
        txt: ['_ethr-verification.hr.acme.com' => ["ethr-verification={$token}"]],
        cname: ['hr.acme.com' => 'someone-else.example'],
    );

    verifyDomain($tenant)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cname')
        ->assertJsonMissingValidationErrors('txt');

    expect($tenant->fresh()->custom_domain_verified_at)->toBeNull();
});

it('refuses a TXT record left behind for an earlier assignment', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    putDomain($tenant, 'hr.acme.com')->assertOk();
    $oldToken = $tenant->fresh()->custom_domain_token;

    // Moved away and back: a new assignment, so a new token.
    putDomain($tenant, 'people.acme.com')->assertOk();
    putDomain($tenant, 'hr.acme.com')->assertOk();
    fakeDns(
        txt: ['_ethr-verification.hr.acme.com' => ["ethr-verification={$oldToken}"]],
        cname: ['hr.acme.com' => 'ethr.et'],
    );

    verifyDomain($tenant)->assertUnprocessable()->assertJsonValidationErrors('txt');
});

it('leaves a verified domain verified, whatever DNS answers at that moment', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com', 'custom_domain_verified_at' => now()]);
    domainConsoleAdmin();
    fakeDns();

    verifyDomain($tenant)->assertOk()->assertJsonPath('custom_domain_status', 'verified');

    expect($tenant->fresh()->hasVerifiedCustomDomain())->toBeTrue();
});

it('has nothing to verify when no domain is assigned', function () {
    $tenant = domainTenant();
    domainConsoleAdmin();
    fakeDns();

    verifyDomain($tenant)
        ->assertUnprocessable()
        ->assertJsonPath('errors.custom_domain.0', __('validation.custom_domain_none'));
});

// ── Read side and access ────────────────────────────────────────────────────

it('reports the domain, its state and the plan gate on the tenant detail and in the list', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com']);
    domainConsoleAdmin();

    test()->getJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}")
        ->assertOk()
        ->assertJsonPath('custom_domain', 'hr.acme.com')
        ->assertJsonPath('custom_domain_status', 'pending')
        ->assertJsonPath('custom_domain_dns.txt_name', '_ethr-verification.hr.acme.com')
        ->assertJsonPath('custom_domain_allowed', true);

    $row = collect(test()->getJson('http://admin.ethr.et/api/v1/admin/tenants')->assertOk()->json('data'))
        ->firstWhere('public_id', $tenant->public_id);
    expect($row['custom_domain'])->toBe('hr.acme.com')
        ->and($row['custom_domain_status'])->toBe('pending');
});

it('tells the console when the plan does not allow a domain', function () {
    $tenant = domainTenant(planIncludesDomain: false);
    domainConsoleAdmin();

    test()->getJson("http://admin.ethr.et/api/v1/admin/tenants/{$tenant->public_id}")
        ->assertOk()
        ->assertJsonPath('custom_domain_allowed', false);
});

it('never shows the verification token to the tenant\'s own people', function () {
    $tenant = domainTenant(['custom_domain' => 'hr.acme.com']);

    expect($tenant->fresh()->toArray())->not->toHaveKey('custom_domain_token');
});

it('refuses a tenant admin', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson("http://acme.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/domain", ['custom_domain' => 'hr.acme.com'])
        ->assertForbidden();
    test()->postJson("http://acme.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/domain/verify")
        ->assertForbidden();

    expect($tenant->fresh()->custom_domain)->toBeNull();
});
