<?php

declare(strict_types=1);

use App\Enums\UserRole;

/*
 * The Reverb private-channel auth endpoint must live under /api/v1 so the
 * httpOnly access_token cookie (path=/api) reaches it and AuthenticateFromCookie
 * can resolve the user. These tests guard that the route exists and is protected;
 * channel-authorization logic lives in routes/channels.php.
 */

test('broadcasting auth endpoint requires authentication', function () {
    $tenant = createTenant();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/broadcasting/auth", [
        'socket_id' => '123.456',
        'channel_name' => 'private-user.01HXXXXXXXXXXXXXXXXXXXXXXX',
    ])->assertUnauthorized();
});

test('authenticated user can reach the broadcasting auth endpoint', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::EMPLOYEE], $tenant);
    $token = $user->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/broadcasting/auth", [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$user->public_id}",
        ])->assertOk();
});
