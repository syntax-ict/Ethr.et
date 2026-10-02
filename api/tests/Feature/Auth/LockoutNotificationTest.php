<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * PHASE_00 S02 — "Account lockout after 10 failed attempts (15-min cooldown,
 * notify tenant admin)".
 *
 * RateLimitLoginAttempts enforces the lockout. The "notify" half lived in
 * LoginAttemptService, which nothing on the login path ever called: these
 * tests drove that service directly and passed for an alert that never ran.
 * They now drive the real login endpoint.
 */
function lockoutFixture(): array
{
    $tenant = createTenant();
    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'status' => 'active',
    ]);

    return [$tenant, $admin];
}

function failedLogin(Tenant $tenant, string $email = 'victim@test.com'): TestResponse
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $email,
        'password' => 'definitely-not-the-password',
    ], ['REMOTE_ADDR' => '10.0.0.9']);
}

/** $count failed logins, stepping past the 5-per-minute burst window. */
function failLogins(Tenant $tenant, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        failedLogin($tenant);
        if ($i % 5 === 0) {
            test()->travel(61)->seconds();
        }
    }
}

test('the tenant admin is notified when an account locks out', function () {
    Notification::fake();
    [$tenant, $admin] = lockoutFixture();

    failLogins($tenant, 10);

    Notification::assertSentTo($admin, SystemAlertNotification::class);
    failedLogin($tenant)->assertStatus(429);
});

test('the alert fires once, not on every further failed attempt', function () {
    Notification::fake();
    [$tenant, $admin] = lockoutFixture();

    // Well past the threshold — a running attack must not become a mail flood
    // aimed at the very admins who need to read the first alert.
    failLogins($tenant, 25);

    Notification::assertSentToTimes($admin, SystemAlertNotification::class, 1);
});

test('no alert is sent before the threshold is reached', function () {
    Notification::fake();
    [$tenant] = lockoutFixture();

    failLogins($tenant, 9);

    Notification::assertNothingSent();
});

test('another tenant admin is not notified', function () {
    Notification::fake();
    [$tenant] = lockoutFixture();
    $otherAdmin = User::factory()->create([
        'tenant_id' => createTenant()->id,
        'role' => UserRole::TENANT_ADMIN,
        'status' => 'active',
    ]);

    failLogins($tenant, 10);

    Notification::assertNotSentTo($otherAdmin, SystemAlertNotification::class);
});

test('a lockout with no active admin does not raise', function () {
    Notification::fake();
    $tenant = createTenant();

    failLogins($tenant, 10);

    failedLogin($tenant)->assertStatus(429);
});

test('a notification failure does not break the lockout itself', function () {
    [$tenant] = lockoutFixture();

    // Not faked: with no mail transport reachable the send throws, and the
    // lockout must still take effect rather than surfacing as a login error.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    failLogins($tenant, 10);

    failedLogin($tenant)->assertStatus(429);
});
