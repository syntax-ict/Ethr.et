<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\MfaResetNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/*
 * The way back from a lost authenticator. Until 2026-10-09 there was none:
 * no recovery codes, no one who could turn MFA off for another user, and the
 * sign-in screen linked to a recovery page that did not exist.
 */

function mfaResetTarget(array $attributes = []): User
{
    $actor = auth()->user();

    return createUser(array_merge([
        'role' => UserRole::EMPLOYEE,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
    ], $attributes), $actor->tenant);
}

it('lets a tenant admin turn off MFA for someone who lost their device, forgetting their trusted browsers', function () {
    Notification::fake();
    actingAsUser(['role' => UserRole::TENANT_ADMIN]);
    $target = mfaResetTarget();
    TrustedDevice::create([
        'user_id' => $target->id,
        'device_hash' => hash('sha256', 'browser'),
        'device_name' => 'Chrome',
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $this->postJson("/api/v1/users/{$target->public_id}/mfa/reset")
        ->assertOk()
        ->assertJsonPath('mfa_enabled', false);

    $target->refresh();
    expect($target->mfa_enabled)->toBeFalse()
        ->and($target->mfa_secret)->toBeNull()
        ->and(TrustedDevice::where('user_id', $target->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'user.mfa_reset')->where('auditable_id', $target->id)->exists())->toBeTrue();

    Notification::assertSentTo($target, MfaResetNotification::class);
});

it('is a tenant-admin action: an HR admin cannot reset MFA', function () {
    actingAsUser(['role' => UserRole::HR_ADMIN]);
    $target = mfaResetTarget();

    $this->postJson("/api/v1/users/{$target->public_id}/mfa/reset")->assertForbidden();

    expect($target->refresh()->mfa_enabled)->toBeTrue();
});

it('cannot reset your own MFA, which needs a current code from the security page', function () {
    $actor = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP']);

    $this->postJson("/api/v1/users/{$actor->public_id}/mfa/reset")
        ->assertUnprocessable()
        ->assertJsonPath('detail', __('user.errors.cannot_reset_own_mfa'));

    expect($actor->refresh()->mfa_enabled)->toBeTrue();
});

it('says so when the user has no MFA to reset', function () {
    actingAsUser(['role' => UserRole::TENANT_ADMIN]);
    $target = mfaResetTarget(['mfa_enabled' => false, 'mfa_secret' => null]);

    $this->postJson("/api/v1/users/{$target->public_id}/mfa/reset")->assertStatus(409);
});

it('cannot reach a user in another tenant', function () {
    // Made first: createTenant() makes its tenant current, and signing in
    // afterwards makes the admin's tenant current again.
    $other = createUser(['role' => UserRole::EMPLOYEE, 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'], createTenant());
    actingAsUser(['role' => UserRole::TENANT_ADMIN]);

    $this->postJson("/api/v1/users/{$other->public_id}/mfa/reset")->assertNotFound();

    expect($other->refresh()->mfa_enabled)->toBeTrue();
});

// ── ethr:reset-mfa — for the accounts no one in the app can reset ────────────

it('resets a platform super admin from the console, who has no one above them', function () {
    Notification::fake();
    $admin = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'email' => 'ops@ethr.test',
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $this->artisan('ethr:reset-mfa', ['email' => 'ops@ethr.test'])->assertSuccessful();

    expect($admin->refresh()->mfa_enabled)->toBeFalse();
    Notification::assertSentTo($admin, MfaResetNotification::class);
});

it('resets a tenant\'s only admin from the console when named with --tenant', function () {
    Notification::fake();
    $tenant = createTenant();
    $owner = createUser(['role' => UserRole::TENANT_ADMIN, 'email' => 'owner@acme.test', 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'], $tenant);
    app(CurrentTenant::class)->forget();

    $this->artisan('ethr:reset-mfa', ['email' => 'owner@acme.test', '--tenant' => $tenant->subdomain])->assertSuccessful();

    expect($owner->refresh()->mfa_enabled)->toBeFalse();
});

it('never reaches a tenant user without --tenant, and refuses an unknown slug', function () {
    $tenant = createTenant();
    $user = createUser(['email' => 'same@acme.test', 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'], $tenant);
    app(CurrentTenant::class)->forget();

    $this->artisan('ethr:reset-mfa', ['email' => 'same@acme.test'])->assertFailed();
    $this->artisan('ethr:reset-mfa', ['email' => 'same@acme.test', '--tenant' => 'no-such-org'])->assertFailed();

    expect($user->refresh()->mfa_enabled)->toBeTrue();
});
