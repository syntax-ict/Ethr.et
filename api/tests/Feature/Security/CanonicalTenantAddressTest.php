<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use Illuminate\Testing\TestResponse;

/**
 * One canonical address per organisation (owner decision, 2026-10-08).
 *
 *   - Enterprise: a VERIFIED custom domain (`hr.acme.com`), a paid add-on.
 *   - Standard: the subdomain (`acme.ethr.et`), once TENANCY_SUBDOMAINS=true.
 *   - Fallback: `ethr.et/acme`, which only redirects.
 *
 * The entry URL and a sign-in on the apex send an organisation to the first of
 * those it has, and only fall back to `/login?org=` when it has neither. A
 * custom domain that is merely assigned, not verified, is no address at all.
 *
 * @see docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md
 */
function canonicalTenant(string $slug, array $attributes = []): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug] + $attributes);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'email' => "admin@{$slug}.test",
        'password' => 'correct-horse-battery',
    ]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $admin];
}

function verifiedDomain(string $domain): array
{
    return ['custom_domain' => $domain, 'custom_domain_verified_at' => now()];
}

function onProductionHosts(bool $subdomains = false): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.domain' => 'ethr.et',
        'app.frontend_url' => 'https://ethr.et',
        'tenancy.subdomains' => $subdomains,
    ]);
    app(CurrentTenant::class)->forget();
}

function signIn(string $url, string $slug): TestResponse
{
    return test()->postJson($url, [
        'email' => "admin@{$slug}.test",
        'password' => 'correct-horse-battery',
        'tenant' => $slug,
    ]);
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

// ── The entry URL redirects to the canonical address ────────────────────────

it('sends ethr.et/{slug} to the organisation\'s verified custom domain', function () {
    canonicalTenant('acme', verifiedDomain('hr.acme.com'));
    onProductionHosts(subdomains: true);

    test()->get('http://ethr.et/acme')->assertRedirect('https://hr.acme.com/login');
});

it('sends ethr.et/{slug} to the organisation\'s subdomain once subdomains are served', function () {
    canonicalTenant('acme');
    onProductionHosts(subdomains: true);

    test()->get('http://ethr.et/acme')->assertRedirect('https://acme.ethr.et/login');
});

it('falls back to /login?org= only when the organisation has no address of its own', function () {
    canonicalTenant('acme', ['custom_domain' => 'hr.acme.com']);
    onProductionHosts(subdomains: false);

    // The domain is assigned but pending, so it is not an address yet.
    test()->get('http://ethr.et/acme')->assertRedirect('/login?org=acme');
});

// ── A sign-in on the apex hands off to the canonical address ────────────────

it('hands an apex sign-in to the verified custom domain before checking credentials', function () {
    canonicalTenant('acme', verifiedDomain('hr.acme.com'));
    onProductionHosts();

    signIn('http://ethr.et/api/v1/auth/login', 'acme')
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://ethr.et/errors/canonical-address')
        ->assertJsonPath('canonical_url', 'https://hr.acme.com/login');

    // Nothing was authenticated on the apex: the session there stays empty.
    test()->assertGuest('web');
});

it('hands an apex sign-in to the subdomain once subdomains are served', function () {
    canonicalTenant('acme');
    onProductionHosts(subdomains: true);

    signIn('http://ethr.et/api/v1/auth/login', 'acme')
        ->assertStatus(409)
        ->assertJsonPath('canonical_url', 'https://acme.ethr.et/login');
});

it('hands a sign-in on the subdomain to the verified custom domain above it', function () {
    canonicalTenant('acme', verifiedDomain('hr.acme.com'));
    onProductionHosts(subdomains: true);

    signIn('http://acme.ethr.et/api/v1/auth/login', 'acme')
        ->assertStatus(409)
        ->assertJsonPath('canonical_url', 'https://hr.acme.com/login');
});

it('signs in at the canonical address itself', function () {
    canonicalTenant('acme', verifiedDomain('hr.acme.com'));
    onProductionHosts(subdomains: true);

    signIn('http://hr.acme.com/api/v1/auth/login', 'acme')->assertOk();
});

it('signs in on the apex when the organisation has no address of its own', function () {
    canonicalTenant('acme', ['custom_domain' => 'hr.acme.com']);
    onProductionHosts(subdomains: false);

    signIn('http://ethr.et/api/v1/auth/login', 'acme')->assertOk();
});

it('never hands off from a host the deployment does not own, which would loop', function () {
    canonicalTenant('acme');
    app(CurrentTenant::class)->forget();
    config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'http://localhost:3000', 'tenancy.subdomains' => true]);

    // A dev proxy forwarding to localhost cannot be the canonical address.
    signIn('http://localhost/api/v1/auth/login', 'acme')->assertOk();
});

// ── A pending domain is not an address ──────────────────────────────────────

it('resolves nothing on a custom domain that is assigned but not verified', function () {
    canonicalTenant('acme', ['name' => 'Acme Ltd', 'custom_domain' => 'hr.acme.com']);
    onProductionHosts();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

it('builds no link on a custom domain that is assigned but not verified', function () {
    canonicalTenant('acme', ['custom_domain' => 'hr.acme.com']);
    onProductionHosts(subdomains: false);

    expect(FrontendUrl::forTenant('acme', '/login'))->toBe('https://ethr.et/acme');
});

it('starts verification over, with a new token, whenever the domain changes', function () {
    [$tenant] = canonicalTenant('acme', verifiedDomain('hr.acme.com'));
    $tenant->forceFill(['custom_domain_token' => 'old-token'])->save();

    $tenant->update(['custom_domain' => 'people.acme.com']);
    $tenant->refresh();

    expect($tenant->custom_domain_verified_at)->toBeNull()
        ->and($tenant->custom_domain_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($tenant->hasVerifiedCustomDomain())->toBeFalse();

    $tenant->update(['custom_domain' => null]);

    expect($tenant->refresh()->custom_domain_token)->toBeNull();
});

it('keeps the verification when a save leaves the domain alone', function () {
    [$tenant] = canonicalTenant('acme', verifiedDomain('hr.acme.com'));

    $tenant->update(['name' => 'Acme Holdings']);

    expect($tenant->refresh()->hasVerifiedCustomDomain())->toBeTrue();
});
