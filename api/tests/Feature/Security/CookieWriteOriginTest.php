<?php

declare(strict_types=1);

use App\Services\Auth\SessionCookie;

// A-Z review I-04 (2026-11-05). A write signs in with the session cookie only
// from this host, from a trusted origin, or with no origin named. Laravel
// skips the XSRF check under test, so this pins the sign-in decision itself;
// the XSRF answer for trusted origins was checked against a running server.

function cookieWrite(string $host, ?string $origin)
{
    $tenant = createTenant();
    $user = createUser([], $tenant);
    $token = $user->createToken('session')->plainTextToken;

    $headers = ['Accept' => 'application/json'];
    if ($origin !== null) {
        $headers['Origin'] = $origin;
    }

    return [
        test()->withUnencryptedCookie(SessionCookie::NAME, $token)
            ->withHeaders($headers)
            ->post("http://{$tenant->subdomain}.{$host}/api/v1/auth/logout"),
        $user,
    ];
}

it('does not sign a write in with the cookie from an origin it does not trust', function () {
    [$response, $user] = cookieWrite('ethr.test', 'https://blog.example.org');

    $response->assertStatus(401);
    expect($user->tokens()->count())->toBe(1);
});

it('signs a write in with the cookie from a trusted origin or with none', function (?string $origin) {
    [$response, $user] = cookieWrite('ethr.test', $origin);

    $response->assertOk();
    expect($user->tokens()->count())->toBe(0);
})->with([
    'trusted origin' => 'http://localhost:3000',
    'no origin' => null,
]);

it('treats the host the request came to as its own origin', function () {
    $tenant = createTenant();
    $user = createUser([], $tenant);
    $token = $user->createToken('session')->plainTextToken;
    $host = "{$tenant->subdomain}.ethr.test";

    test()->withUnencryptedCookie(SessionCookie::NAME, $token)
        ->withHeaders(['Accept' => 'application/json', 'Origin' => "http://{$host}"])
        ->post("http://{$host}/api/v1/auth/logout")
        ->assertOk();
});

it('leaves reads from any origin signed in', function () {
    $tenant = createTenant();
    $user = createUser([], $tenant);
    $token = $user->createToken('session')->plainTextToken;

    test()->withUnencryptedCookie(SessionCookie::NAME, $token)
        ->withHeaders(['Accept' => 'application/json', 'Origin' => 'https://blog.example.org'])
        ->get("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")
        ->assertOk();
});
