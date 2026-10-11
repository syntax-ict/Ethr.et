<?php

declare(strict_types=1);

use App\Enums\TenantAddressMode;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Services\Auth\SessionHandoff;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;

/**
 * An organisation chooses how it is reached (owner decision 2026-10-11):
 * `path` (ethr.et/{slug}), `subdomain` ({slug}.ethr.et) or `custom_domain`.
 * These tests pin that the choice decides every address ETHR hands out, and
 * that a choice the deployment cannot honour yet falls back rather than
 * pointing anyone at a host that does not answer.
 */
function addressModeTenant(string $slug, array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug] + $attributes);
    app(CurrentTenant::class)->forget();

    return $tenant;
}

function addressModeAdmin(Tenant $tenant): User
{
    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'email' => "admin@{$tenant->subdomain}.test",
        'password' => 'correct-horse-battery',
    ]);
}

function addressModeHosts(bool $subdomains): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.domain' => 'ethr.et',
        'app.frontend_url' => 'https://ethr.et',
        'tenancy.subdomains' => $subdomains,
    ]);
    app(CurrentTenant::class)->forget();
}

function addressModeVerified(string $domain): array
{
    return ['custom_domain' => $domain, 'custom_domain_verified_at' => now()];
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

// ── The default, for an organisation that has not chosen ────────────────────

it('gives an organisation that has not chosen what the fixed tiering always gave it', function () {
    addressModeHosts(subdomains: false);
    $plain = addressModeTenant('plain');
    $owned = addressModeTenant('owned', addressModeVerified('hr.owned.com'));

    expect($plain->address_mode)->toBeNull()
        ->and($plain->addressMode())->toBe(TenantAddressMode::PATH)
        ->and(FrontendUrl::canonicalOrigin($plain))->toBeNull()
        ->and($owned->addressMode())->toBe(TenantAddressMode::CUSTOM_DOMAIN)
        ->and(FrontendUrl::canonicalOrigin($owned))->toBe('https://hr.owned.com');

    addressModeHosts(subdomains: true);

    expect($plain->addressMode())->toBe(TenantAddressMode::SUBDOMAIN)
        ->and(FrontendUrl::canonicalOrigin($plain))->toBe('https://plain.ethr.et');
});

// ── The choice wins over what the organisation happens to hold ──────────────

it('lets an organisation choose the path even while it holds a verified domain and subdomains are served', function () {
    addressModeHosts(subdomains: true);
    $tenant = addressModeTenant('acme', addressModeVerified('hr.acme.com') + ['address_mode' => TenantAddressMode::PATH]);
    addressModeAdmin($tenant);

    expect($tenant->effectiveAddressMode())->toBe(TenantAddressMode::PATH)
        ->and(FrontendUrl::canonicalOrigin($tenant))->toBeNull();

    // The entry URL lands on the shared host, and an apex sign-in is not
    // handed off anywhere: the organisation said this is its address.
    test()->get('http://ethr.et/acme')->assertRedirect('/login?org=acme');

    test()->postJson('http://ethr.et/api/v1/auth/login', [
        'email' => 'admin@acme.test',
        'password' => 'correct-horse-battery',
        'tenant' => 'acme',
    ])->assertOk();
});

it('hands an apex sign-in to the chosen subdomain, not to a verified domain the organisation did not choose', function () {
    addressModeHosts(subdomains: true);
    $tenant = addressModeTenant('acme', addressModeVerified('hr.acme.com') + ['address_mode' => TenantAddressMode::SUBDOMAIN]);
    addressModeAdmin($tenant);

    test()->postJson('http://ethr.et/api/v1/auth/login', [
        'email' => 'admin@acme.test',
        'password' => 'correct-horse-battery',
        'tenant' => 'acme',
    ])->assertStatus(409)->assertJsonPath('canonical_url', 'https://acme.ethr.et/login');
});

// ── A choice the deployment cannot honour yet falls back, and switches on its own ──

it('keeps a chosen subdomain and uses the path until the host serves subdomains', function () {
    addressModeHosts(subdomains: false);
    $tenant = addressModeTenant('acme', ['address_mode' => TenantAddressMode::SUBDOMAIN]);

    expect($tenant->addressMode())->toBe(TenantAddressMode::SUBDOMAIN)
        ->and($tenant->effectiveAddressMode())->toBe(TenantAddressMode::PATH)
        ->and(FrontendUrl::canonicalOrigin($tenant))->toBeNull()
        ->and(FrontendUrl::at($tenant, 'login'))->toBe('https://ethr.et/acme');

    // The host work is done: nothing else changes, and the links move.
    config(['tenancy.subdomains' => true]);

    expect($tenant->effectiveAddressMode())->toBe(TenantAddressMode::SUBDOMAIN)
        ->and(FrontendUrl::at($tenant, 'login'))->toBe('https://acme.ethr.et/login');
});

it('keeps a chosen custom domain and uses the fallback until the domain verifies', function () {
    addressModeHosts(subdomains: true);
    $tenant = addressModeTenant('acme', ['custom_domain' => 'hr.acme.com', 'address_mode' => TenantAddressMode::CUSTOM_DOMAIN]);

    expect($tenant->custom_domain_verified_at)->toBeNull()
        ->and($tenant->effectiveAddressMode())->toBe(TenantAddressMode::SUBDOMAIN)
        ->and(FrontendUrl::canonicalOrigin($tenant))->toBe('https://acme.ethr.et');

    $tenant->forceFill(['custom_domain_verified_at' => now()])->save();

    expect($tenant->effectiveAddressMode())->toBe(TenantAddressMode::CUSTOM_DOMAIN)
        ->and(FrontendUrl::canonicalOrigin($tenant))->toBe('https://hr.acme.com');
});

// ── Every link follows the choice ───────────────────────────────────────────

it('builds the password reset link at the organisation\'s address', function () {
    addressModeHosts(subdomains: true);
    $tenant = addressModeTenant('acme', ['address_mode' => TenantAddressMode::SUBDOMAIN]);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $url = (new PasswordResetLinkNotification('tok123', 'acme'))->toMail($user)->actionUrl;

    expect($url)->toStartWith('https://acme.ethr.et/login/reset?token=tok123')
        ->and($url)->toContain('tenant=acme');

    $tenant->update(['address_mode' => TenantAddressMode::PATH]);

    expect((new PasswordResetLinkNotification('tok123', 'acme'))->toMail($user)->actionUrl)
        ->toStartWith('https://ethr.et/login/reset?token=tok123');
});

it('links a notification to the recipient\'s organisation, and a platform account to the shared host', function () {
    addressModeHosts(subdomains: true);
    $tenant = addressModeTenant('acme', ['address_mode' => TenantAddressMode::SUBDOMAIN]);
    $member = User::factory()->create(['tenant_id' => $tenant->id]);
    $platform = User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN]);

    expect(FrontendUrl::forNotifiable($member, '/approvals'))->toBe('https://acme.ethr.et/approvals')
        ->and(FrontendUrl::forNotifiable($platform, '/approvals'))->toBe('https://ethr.et/approvals')
        ->and(FrontendUrl::forNotifiable(new stdClass, '/approvals'))->toBe('https://ethr.et/approvals');
});

it('sends an impersonation hand-off to the organisation\'s address, naming it on the shared host', function () {
    addressModeHosts(subdomains: true);
    $path = addressModeTenant('path-org', ['address_mode' => TenantAddressMode::PATH]);
    $sub = addressModeTenant('sub-org', ['address_mode' => TenantAddressMode::SUBDOMAIN]);
    $own = addressModeTenant('own-org', addressModeVerified('hr.own.com') + ['address_mode' => TenantAddressMode::CUSTOM_DOMAIN]);

    // On the shared host the hostname names nothing, so the claim page is
    // told which organisation to name in X-Tenant. The nonce stays in the
    // fragment, where it reaches no server log.
    expect(SessionHandoff::urlFor($path, 'n0nce'))->toBe('https://ethr.et/impersonate/claim?org=path-org#nonce=n0nce')
        ->and(SessionHandoff::urlFor($sub, 'n0nce'))->toBe('https://sub-org.ethr.et/impersonate/claim#nonce=n0nce')
        ->and(SessionHandoff::urlFor($own, 'n0nce'))->toBe('https://hr.own.com/impersonate/claim#nonce=n0nce');
});

it('reaches the entry URL in either case', function () {
    addressModeHosts(subdomains: false);
    addressModeTenant('acme');

    test()->get('http://ethr.et/Acme')->assertRedirect('/login?org=acme');
});
