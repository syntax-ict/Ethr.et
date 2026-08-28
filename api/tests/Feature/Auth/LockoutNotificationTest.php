<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\LoginAttemptService;
use Illuminate\Support\Facades\Notification;

/**
 * PHASE_00 S02 — "Account lockout after 10 failed attempts (15-min cooldown,
 * notify tenant admin)".
 *
 * The lockout itself was implemented; the notification half was not —
 * `lockoutAccount()` wrote a cache key and nothing else, so the admins who most
 * needed to know about a brute-force attempt were never told.
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

function failUntilLockout(Tenant $tenant, string $email = 'victim@test.com'): void
{
    $service = app(LoginAttemptService::class);

    for ($i = 0; $i < 10; $i++) {
        $service->recordFailedAttempt($email, '10.0.0.9', $tenant);
    }
}

test('the tenant admin is notified when an account locks out', function () {
    Notification::fake();

    [$tenant, $admin] = lockoutFixture();

    failUntilLockout($tenant);

    Notification::assertSentTo($admin, SystemAlertNotification::class);
});

test('the alert fires once, not on every further failed attempt', function () {
    Notification::fake();

    [$tenant, $admin] = lockoutFixture();

    // Well past the threshold — a running attack must not become a mail flood
    // aimed at the very admins who need to read the first alert.
    $service = app(LoginAttemptService::class);
    for ($i = 0; $i < 25; $i++) {
        $service->recordFailedAttempt('victim@test.com', '10.0.0.9', $tenant);
    }

    Notification::assertSentToTimes($admin, SystemAlertNotification::class, 1);
});

test('no alert is sent before the threshold is reached', function () {
    Notification::fake();

    [$tenant] = lockoutFixture();

    $service = app(LoginAttemptService::class);
    for ($i = 0; $i < 9; $i++) {
        $service->recordFailedAttempt('victim@test.com', '10.0.0.9', $tenant);
    }

    Notification::assertNothingSent();
});

test('another tenant admin is not notified', function () {
    Notification::fake();

    [$tenant] = lockoutFixture();
    $otherTenant = createTenant();
    $otherAdmin = User::factory()->create([
        'tenant_id' => $otherTenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'status' => 'active',
    ]);

    failUntilLockout($tenant);

    Notification::assertNotSentTo($otherAdmin, SystemAlertNotification::class);
});

test('a lockout with no active admin does not raise', function () {
    Notification::fake();

    $tenant = createTenant();

    // No admin exists for this tenant — the lockout must still be applied.
    failUntilLockout($tenant);

    expect(app(LoginAttemptService::class)->isLockedOut('victim@test.com', '10.0.0.9', $tenant))
        ->toBeTrue();
});

test('a notification failure does not break the lockout itself', function () {
    [$tenant] = lockoutFixture();

    // Not faked: with no mail transport reachable the send throws, and the
    // lockout must still take effect rather than surfacing as a login-path error.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    failUntilLockout($tenant);

    expect(app(LoginAttemptService::class)->isLockedOut('victim@test.com', '10.0.0.9', $tenant))
        ->toBeTrue();
});
