<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/**
 * PHASE_00 S03 — "Password policy: configurable min length, complexity, expiry
 * (tenant setting)". Before this, every password path used a bare `min:8` and
 * the phrase "password_policy" appeared nowhere in the codebase.
 */
function policyUser(array $policy = []): array
{
    $tenant = createTenant();

    if ($policy !== []) {
        $tenant->update(['settings' => array_merge(
            is_array($tenant->settings) ? $tenant->settings : [],
            ['password_policy' => $policy],
        )]);
    }

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'password' => bcrypt('current-password'),
    ], $tenant);

    return [$tenant->fresh(), $user];
}

function changePassword(string $subdomain, string $new): TestResponse
{
    return test()->postJson("http://{$subdomain}.ethr.test/api/v1/auth/password/change", [
        'current_password' => 'current-password',
        'password' => $new,
        'password_confirmation' => $new,
    ]);
}

// ── Defaults preserve the previous behaviour ──

test('the default policy matches the old bare min:8', function () {
    expect(PasswordPolicy::forTenant(null))->toMatchArray([
        'min_length' => 8,
        'require_uppercase' => false,
        'require_number' => false,
        'expiry_days' => 0,
    ]);
});

test('an 8-character password is still accepted with no policy configured', function () {
    [$tenant, $user] = policyUser();

    test()->actingAs($user);

    changePassword($tenant->subdomain, 'abcdefgh')->assertOk();
});

test('a 7-character password is still rejected', function () {
    [$tenant, $user] = policyUser();

    test()->actingAs($user);

    changePassword($tenant->subdomain, 'abcdefg')->assertStatus(422);
});

// ── Configured policy ──

test('a tenant can require a longer minimum length', function () {
    [$tenant, $user] = policyUser(['min_length' => 14]);

    test()->actingAs($user);

    changePassword($tenant->subdomain, 'abcdefghijk')->assertStatus(422);
    changePassword($tenant->subdomain, 'abcdefghijklmn')->assertOk();
});

test('a tenant can require complexity', function () {
    [$tenant, $user] = policyUser([
        'require_uppercase' => true,
        'require_number' => true,
        'require_symbol' => true,
    ]);

    test()->actingAs($user);

    changePassword($tenant->subdomain, 'alllowercase')->assertStatus(422);
    changePassword($tenant->subdomain, 'Uppercase123')->assertStatus(422); // no symbol
    changePassword($tenant->subdomain, 'Uppercase123!')->assertOk();
});

test('a tenant cannot configure below the platform floor of 8', function () {
    [$tenant] = policyUser(['min_length' => 3]);

    expect(PasswordPolicy::forTenant($tenant)['min_length'])->toBe(8);
});

test('a malformed policy falls back to defaults rather than throwing', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['password_policy' => 'not-an-array']]);

    expect(PasswordPolicy::forTenant($tenant->fresh()))->toBe(PasswordPolicy::DEFAULTS);
});

test('unknown policy keys are ignored', function () {
    [$tenant] = policyUser(['min_length' => 12, 'allow_anything' => true]);

    $policy = PasswordPolicy::forTenant($tenant);

    expect($policy)->not->toHaveKey('allow_anything');
    expect($policy['min_length'])->toBe(12);
});

// ── Expiry ──

test('expiry is off by default', function () {
    expect(PasswordPolicy::isExpired(null, now()->subYears(5)))->toBeFalse();
});

test('a password past the configured expiry is reported expired', function () {
    [$tenant] = policyUser(['expiry_days' => 90]);

    expect(PasswordPolicy::isExpired($tenant, now()->subDays(91)))->toBeTrue();
    expect(PasswordPolicy::isExpired($tenant, now()->subDays(30)))->toBeFalse();
});

test('a never-changed password is not treated as expired', function () {
    [$tenant] = policyUser(['expiry_days' => 90]);

    expect(PasswordPolicy::isExpired($tenant, null))->toBeFalse();
});

// ── Enforcement at sign-in ──

test('login reports an expired password without locking the user out', function () {
    [$tenant, $user] = policyUser(['expiry_days' => 30]);
    $user->update(['password_changed_at' => now()->subDays(60)]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'current-password',
    ]);

    $response->assertOk();
    expect($response->json('password_expired'))->toBeTrue();
    // A token is still issued: blocking sign-in would lock the user out of the
    // only screen that can clear the condition.
    expect($response->json('access_token'))->not->toBeNull();
});

test('login reports a fresh password as not expired', function () {
    [$tenant, $user] = policyUser(['expiry_days' => 30]);
    $user->update(['password_changed_at' => now()->subDays(2)]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'current-password',
    ])->assertOk()->assertJsonPath('password_expired', false);
});

test('an account that predates the column is never forced to change', function () {
    [$tenant, $user] = policyUser(['expiry_days' => 30]);
    $user->update(['password_changed_at' => null]);

    // Backfilling would either lie about when the password was set or expire
    // every long-lived account the moment a tenant enables the policy.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'current-password',
    ])->assertOk()->assertJsonPath('password_expired', false);
});

test('changing a password restarts the expiry clock', function () {
    [$tenant, $user] = policyUser(['expiry_days' => 30]);
    $user->update(['password_changed_at' => now()->subDays(60)]);

    test()->actingAs($user);
    changePassword($tenant->subdomain, 'BrandNewPassword1')->assertOk();

    expect($user->fresh()->password_changed_at->isToday())->toBeTrue();
    expect(PasswordPolicy::isExpired($tenant, $user->fresh()->password_changed_at))->toBeFalse();
});

// ── Applied on the reset path too ──

test('the policy also governs password reset', function () {
    [$tenant, $user] = policyUser(['min_length' => 14]);

    $token = Password::broker()->createToken($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/reset", [
        'email' => $user->email,
        'token' => $token,
        'password' => 'short-one',
        'password_confirmation' => 'short-one',
    ])->assertStatus(422);
});
