<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionCookie;

/*
 * Production QA 2026-10-11, P16: after "Log out" on a tenant host the browser
 * was still signed in — `POST /auth/logout` answered 200, and `/auth/me` and
 * the data endpoints kept answering 200 on the same cookie jar.
 *
 * A browser sign-in is authenticated twice over: `LoginRequest` calls
 * `Auth::login()`, so the Laravel session holds the user, and the response
 * also sets the bearer-token cookie. Sanctum consults the session first, so on
 * the logout request `currentAccessToken()` is a TransientToken — deleting it
 * deletes nothing — and logout never ended the session. These tests drive the
 * real login endpoint, so they cover the half `actingAs()` cannot.
 */

/** Sanctum treats a request as the SPA's — and so starts the session — by Origin. */
function p16Spa(): array
{
    return ['Origin' => 'http://localhost:3000'];
}

function p16Url(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/auth/{$path}";
}

/** @return array{0: Tenant, 1: string} the tenant and the login's bearer token */
function p16SignIn(): array
{
    $tenant = createTenant();
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'p16@test.com',
        'password' => bcrypt('password'),
        'role' => UserRole::EMPLOYEE,
    ]);

    $plain = test()->withHeaders(p16Spa())
        ->withCredentials()
        ->postJson(p16Url($tenant, 'login'), ['email' => 'p16@test.com', 'password' => 'password'])
        ->assertOk()
        ->json('access_token');

    app('auth')->forgetGuards();

    return [$tenant, $plain];
}

function p16LogOut(Tenant $tenant, string $plain): void
{
    test()->withHeaders(p16Spa())
        ->withCredentials()
        ->withUnencryptedCookie(SessionCookie::NAME, $plain)
        ->postJson(p16Url($tenant, 'logout'))
        ->assertOk();

    app('auth')->forgetGuards();
}

test('the browser session is signed in after login, by the session alone', function () {
    [$tenant] = p16SignIn();

    // The precondition the defect depends on: no token cookie, still signed in.
    test()->withHeaders(p16Spa())
        ->withCredentials()
        ->getJson(p16Url($tenant, 'me'))
        ->assertOk();
});

test('logging out ends the browser session, not just the token cookie', function () {
    [$tenant, $plain] = p16SignIn();

    p16LogOut($tenant, $plain);

    test()->withHeaders(p16Spa())
        ->withCredentials()
        ->getJson(p16Url($tenant, 'me'))
        ->assertUnauthorized();
});

test('logging out revokes the bearer token the cookie carried', function () {
    [$tenant, $plain] = p16SignIn();

    p16LogOut($tenant, $plain);

    expect(PersonalAccessToken::findToken($plain))->toBeNull();

    // Another tab still holding the old cookie must not sign back in with it.
    test()->withHeaders(p16Spa())
        ->withCredentials()
        ->withUnencryptedCookie(SessionCookie::NAME, $plain)
        ->getJson(p16Url($tenant, 'me'))
        ->assertUnauthorized();
});

test('an API client logging out with a bearer token still revokes that token', function () {
    $tenant = createTenant();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
    $plain = $user->createToken('auth', ['*'], now()->addMinutes(15))->plainTextToken;

    test()->withToken($plain)
        ->postJson(p16Url($tenant, 'logout'))
        ->assertOk();

    expect(PersonalAccessToken::findToken($plain))->toBeNull();
});
