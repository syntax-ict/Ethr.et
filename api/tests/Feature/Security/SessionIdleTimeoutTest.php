<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Services\Auth\SessionCookie;
use App\Services\CurrentTenant;

/*
 * Audit N6: a tenant's `session_timeout_minutes` was saved and read by nothing.
 * An admin who set 30 minutes saw "30" on the settings page while every
 * session lived as long as it always had.
 *
 * The browser is authenticated by the Laravel session (Sanctum's stateful
 * guard) and API clients by a bearer token; both halves are pinned here.
 */

const N6_KEY = 'auth.last_activity_at';

/** Sanctum treats a request as the SPA's — and so starts the session — by Origin. */
function n6Spa(): array
{
    return ['Origin' => 'http://localhost:3000'];
}

function n6TimeoutTenant(?int $minutes): Tenant
{
    return createTenant($minutes === null ? [] : ['settings' => ['session_timeout_minutes' => $minutes]]);
}

function n6Me(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/auth/me";
}

// ── The browser session ──

test('a browser session idle longer than the tenant allows is ended', function () {
    $tenant = n6TimeoutTenant(30);
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // The bearer token in the browser's cookie goes with it: Sanctum falls back
    // to it once the session has no user, so leaving it would undo the sign-out.
    $plain = $user->createToken('auth', ['*'], now()->addMinutes(15))->plainTextToken;

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(31)->getTimestamp()])
        ->withCredentials()
        ->withUnencryptedCookie(SessionCookie::NAME, $plain)
        ->getJson(n6Me($tenant))
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/session-expired');

    expect(PersonalAccessToken::findToken($plain))->toBeNull();
});

test('a browser session inside the timeout carries on, and its clock restarts', function () {
    $tenant = n6TimeoutTenant(30);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(29)->getTimestamp()])
        ->getJson(n6Me($tenant))
        ->assertOk();

    expect(session(N6_KEY))->toBeGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp());
});

test('background polling is checked but does not count as activity', function () {
    $tenant = n6TimeoutTenant(30);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $twentyMinutesAgo = now()->subMinutes(20)->getTimestamp();

    test()->withHeaders(n6Spa() + ['X-ETHR-Background' => '1'])
        ->withSession([N6_KEY => $twentyMinutesAgo])
        ->getJson(n6Me($tenant))
        ->assertOk();

    // The unread-count poll fires every 30 seconds on every page. Had it
    // counted, an open tab would never time out.
    expect(session(N6_KEY))->toBe($twentyMinutesAgo);
});

test('a poll that finds the session expired ends it', function () {
    $tenant = n6TimeoutTenant(30);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->withHeaders(n6Spa() + ['X-ETHR-Background' => '1'])
        ->withSession([N6_KEY => now()->subMinutes(45)->getTimestamp()])
        ->getJson(n6Me($tenant))
        ->assertUnauthorized();
});

test('with no setting the default of 480 minutes applies', function () {
    $tenant = n6TimeoutTenant(null);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(470)->getTimestamp()])
        ->getJson(n6Me($tenant))
        ->assertOk();

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(490)->getTimestamp()])
        ->getJson(n6Me($tenant))
        ->assertUnauthorized();
});

test('a stored value that is not a usable timeout fails closed to the default', function () {
    $tenant = createTenant(['settings' => ['session_timeout_minutes' => 'never']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(490)->getTimestamp()])
        ->getJson(n6Me($tenant))
        ->assertUnauthorized();
});

test('signing in starts the clock afresh', function () {
    $tenant = n6TimeoutTenant(30);
    $user = createUser(['role' => UserRole::TENANT_ADMIN, 'password' => bcrypt('password')], $tenant);

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subHours(10)->getTimestamp()])
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

    // A stale stamp from an earlier session in the same browser would have
    // expired this sign-in on its first request.
    expect(session(N6_KEY))->toBeGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp());
});

test('a platform super admin session is not timed out by a tenant policy', function () {
    $tenant = n6TimeoutTenant(5);
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    app(CurrentTenant::class)->forget();
    test()->actingAs($admin);

    test()->withHeaders(n6Spa())
        ->withSession([N6_KEY => now()->subMinutes(60)->getTimestamp()])
        ->getJson('http://admin.ethr.test/api/v1/admin/tenants')
        ->assertOk();
});

// ── Bearer tokens ──

test('a bearer token idle longer than the tenant allows is refused and revoked', function () {
    $tenant = n6TimeoutTenant(30);
    $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $new = $user->createToken('auth', ['*'], now()->addHour());
    $new->accessToken->forceFill(['last_used_at' => now()->subMinutes(31)])->save();

    test()->withToken($new->plainTextToken)->getJson(n6Me($tenant))->assertUnauthorized();

    // Revoked, so POST /auth/refresh cannot mint a successor from it either.
    expect(PersonalAccessToken::find($new->accessToken->id))->toBeNull();
});

test('a bearer token never used is measured from when it was issued', function () {
    $tenant = n6TimeoutTenant(30);
    $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $new = $user->createToken('auth', ['*'], now()->addHour());
    $new->accessToken->forceFill(['created_at' => now()->subMinutes(31), 'last_used_at' => null])->save();

    test()->withToken($new->plainTextToken)->getJson(n6Me($tenant))->assertUnauthorized();
});

test('a bearer token used inside the timeout works', function () {
    $tenant = n6TimeoutTenant(30);
    $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $new = $user->createToken('auth', ['*'], now()->addHour());
    $new->accessToken->forceFill(['last_used_at' => now()->subMinutes(20)])->save();

    test()->withToken($new->plainTextToken)->getJson(n6Me($tenant))->assertOk();
});

test('a platform super admin bearer token is not timed out by a tenant policy', function () {
    $tenant = n6TimeoutTenant(5);
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    $new = $admin->createToken('auth', ['*']);
    $new->accessToken->forceFill(['last_used_at' => now()->subHours(3)])->save();
    app(CurrentTenant::class)->forget();

    test()->withToken($new->plainTextToken)
        ->getJson('http://admin.ethr.test/api/v1/admin/tenants')
        ->assertOk();
});
