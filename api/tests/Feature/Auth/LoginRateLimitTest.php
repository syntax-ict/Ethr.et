<?php

declare(strict_types=1);

use App\Models\User;

function seedLoginUser(string $email = 'ratelimit@test.com'): array
{
    $tenant = createTenant();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => $email,
        'password' => bcrypt('CorrectPassword1!'),
    ]);

    return [$tenant, $user];
}

function attemptLogin(string $email, string $password = 'wrong-password')
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);
}

describe('login burst rate limit', function () {
    it('allows up to 5 failed attempts per minute', function () {
        seedLoginUser();

        for ($i = 0; $i < 5; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }
    });

    it('blocks the 6th failed attempt within a minute with 429 and Retry-After', function () {
        seedLoginUser();

        for ($i = 0; $i < 5; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }

        $response = attemptLogin('ratelimit@test.com');

        $response->assertStatus(429);
        expect($response->headers->get('Retry-After'))->not->toBeNull();
        expect((int) $response->headers->get('Retry-After'))->toBeLessThanOrEqual(60);
    });

    it('scopes the limit per email+IP, not globally', function () {
        seedLoginUser('victim@test.com');
        seedLoginUser('other@test.com');

        for ($i = 0; $i < 6; $i++) {
            attemptLogin('victim@test.com');
        }

        // A different email from the same IP is unaffected.
        attemptLogin('other@test.com')->assertStatus(422);
    });

    it('clears the counter on a successful login', function () {
        seedLoginUser();

        for ($i = 0; $i < 4; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }

        attemptLogin('ratelimit@test.com', 'CorrectPassword1!')->assertOk();

        // Counter reset: 5 more failures should be allowed before blocking again.
        for ($i = 0; $i < 5; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }

        attemptLogin('ratelimit@test.com')->assertStatus(429);
    });
});

describe('login lockout after repeated abuse', function () {
    it('locks out for 15 minutes after 10 cumulative failures across burst windows', function () {
        seedLoginUser();

        // First burst window: 5 failures.
        for ($i = 0; $i < 5; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }
        attemptLogin('ratelimit@test.com')->assertStatus(429);

        // Let the 1-minute burst window expire, but stay inside the 15-minute lockout window.
        test()->travel(61)->seconds();

        // Second burst window: 5 more failures (10 cumulative failures total).
        for ($i = 0; $i < 5; $i++) {
            attemptLogin('ratelimit@test.com')->assertStatus(422);
        }

        // The burst window is fresh again, but the cumulative lockout should now trip
        // with a much longer retry window than the 1-minute burst throttle.
        $response = attemptLogin('ratelimit@test.com');

        $response->assertStatus(429);
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(60);
    });
});
