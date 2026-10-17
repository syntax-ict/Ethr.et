<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\OtpCode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * On the shared sign-in page the organisation the person typed decides which
 * organisation they sign in to, not the one this browser last signed in to.
 *
 * Reproduced 2026-10-17: a browser that had signed in to one organisation kept
 * its slug in localStorage and sent it as `X-Tenant` on every request from the
 * apex. Typing a second organisation put that slug in the body, but
 * ResolveTenant read the header first, so the sign-in was checked against the
 * first organisation and the correct password was refused with "These
 * credentials do not match our records." Nothing but clearing site data got
 * past it, because the stored slug only changes after a successful sign-in.
 *
 * The body now wins over the header on the shareable sign-in endpoints. That
 * widens nothing: the body was already honoured there when no header was
 * sent, a host that names an organisation still wins over both, and
 * EnsureUserBelongsToTenant still decides every request after sign-in.
 */
function typedTenant(string $slug, array $attributes = []): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug] + $attributes);
    app(CurrentTenant::class)->set($tenant);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'email' => 'abebe@example.com',
        'password' => "{$slug}-password-1",
        'status' => 'active',
    ]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $user];
}

function onSharedApex(bool $subdomains = false): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.domain' => 'ethr.et',
        'app.frontend_url' => 'https://ethr.et',
        'tenancy.subdomains' => $subdomains,
    ]);
    app(CurrentTenant::class)->forget();
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('signs in to the organisation typed, not the one the stored header names', function () {
    typedTenant('acme');
    [, $habruUser] = typedTenant('habru');
    onSharedApex();

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->postJson('http://ethr.et/api/v1/auth/login', [
            'email' => 'abebe@example.com',
            'password' => 'habru-password-1',
            'tenant' => 'habru',
        ])
        ->assertOk();

    expect(Auth::id())->toBe($habruUser->id);
});

it('never authenticates against the stored organisation when another is typed', function () {
    typedTenant('acme');
    typedTenant('habru');
    onSharedApex();

    // The stored organisation's own password: it must not open that
    // organisation when the person said they meant the other one.
    test()->withHeaders(['X-Tenant' => 'acme'])
        ->postJson('http://ethr.et/api/v1/auth/login', [
            'email' => 'abebe@example.com',
            'password' => 'acme-password-1',
            'tenant' => 'habru',
        ])
        ->assertUnprocessable();

    expect(Auth::check())->toBeFalse();
});

it('still signs in by the header alone when nothing is typed', function () {
    [, $acmeUser] = typedTenant('acme');
    onSharedApex();

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->postJson('http://ethr.et/api/v1/auth/login', [
            'email' => 'abebe@example.com',
            'password' => 'acme-password-1',
        ])
        ->assertOk();

    expect(Auth::id())->toBe($acmeUser->id);
});

it('hands off to the typed organisation\'s own address, not the stored one\'s', function () {
    typedTenant('acme');
    typedTenant('habru');
    onSharedApex(subdomains: true);

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->postJson('http://ethr.et/api/v1/auth/login', [
            'email' => 'abebe@example.com',
            'password' => 'habru-password-1',
            'tenant' => 'habru',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://ethr.et/errors/canonical-address')
        ->assertJsonPath('canonical_url', 'https://habru.ethr.et/login');
});

it('lets the host win over a typed organisation on a tenant subdomain', function () {
    [, $acmeUser] = typedTenant('acme');
    typedTenant('habru');
    onSharedApex(subdomains: true);

    test()->postJson('http://acme.ethr.et/api/v1/auth/login', [
        'email' => 'abebe@example.com',
        'password' => 'acme-password-1',
        'tenant' => 'habru',
    ])->assertOk();

    expect(Auth::id())->toBe($acmeUser->id);
});

it('sends a sign-in code for the organisation typed, not the stored one', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();
    [, $acmeUser] = typedTenant('acme');
    [, $habruUser] = typedTenant('habru');
    onSharedApex();

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->postJson('http://ethr.et/api/v1/auth/otp/request', [
            'email' => 'abebe@example.com',
            'tenant' => 'habru',
        ])
        ->assertOk();

    expect(OtpCode::where('user_id', $habruUser->id)->count())->toBe(1)
        ->and(OtpCode::where('user_id', $acmeUser->id)->count())->toBe(0);
});

it('sends a sign-in code on the apex when the organisation is only typed', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();
    [, $habruUser] = typedTenant('habru');
    onSharedApex();

    test()->postJson('http://ethr.et/api/v1/auth/otp/request', [
        'email' => 'abebe@example.com',
        'tenant' => 'habru',
    ])->assertOk();

    expect(OtpCode::where('user_id', $habruUser->id)->count())->toBe(1);
});

it('reads the typed organisation only on the sign-in endpoints', function () {
    [, $acmeUser] = typedTenant('acme');
    typedTenant('habru');
    $token = $acmeUser->createToken('auth', ['*'])->plainTextToken;
    onSharedApex();

    // Everywhere else the body is data, never a tenant selector.
    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://ethr.et/api/v1/employees?tenant=habru')
        ->assertOk();
});
