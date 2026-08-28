<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\TrustedDevice;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\TrustedDeviceService;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PHASE_00 S03 — trusted devices and the short-lived pre-MFA token.
 *
 * `trusted_devices` was a dead table: created by the initial migration and
 * referenced by no model, service, route or test anywhere in the codebase.
 */
function mfaUser(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $secret = app(MfaService::class)->generateSecret();

    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'password' => bcrypt('password'),
        'mfa_enabled' => true,
        'mfa_secret' => encrypt($secret),
    ], $tenant);

    app(CurrentTenant::class)->set($tenant);

    return [$tenant, $user, $secret];
}

/** A request bound to a specific browser identity. */
function requestForDevice(?string $deviceId): Request
{
    $request = Request::create('/api/v1/auth/login', 'POST');

    if ($deviceId !== null) {
        $request->cookies->set(DeviceFingerprint::COOKIE, $deviceId);
    }

    app()->instance('request', $request);

    return $request;
}

// ── Pre-MFA challenge token ──

test('an MFA user gets a 5-minute challenge token, not a full session', function () {
    [, $user] = mfaUser();

    requestForDevice(Str::random(64));

    $result = app(AuthService::class)->login($user);

    expect($result['mfa_required'])->toBeTrue();
    expect($result['expires_in'])->toBe(AuthService::MFA_TOKEN_MINUTES * 60);
    expect($result['mfa_token'])->toBe($result['access_token']);

    // 5 minutes, not the usual 15 — a stolen pre-MFA token has a small window.
    expect($result['expires_in'])->toBe(300);
});

test('a user without MFA gets a full 15-minute session', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'mfa_enabled' => false,
    ], $tenant);
    app(CurrentTenant::class)->set($tenant);

    requestForDevice(Str::random(64));

    $result = app(AuthService::class)->login($user);

    expect($result['mfa_required'])->toBeFalse();
    expect($result['expires_in'])->toBe(900);
    expect($result)->not->toHaveKey('mfa_token');
});

// ── Trusting a device ──

test('a trusted device skips the MFA prompt', function () {
    [, $user] = mfaUser();

    $deviceId = Str::random(64);
    $request = requestForDevice($deviceId);

    app(TrustedDeviceService::class)->trust($user, $request);

    requestForDevice($deviceId);
    $result = app(AuthService::class)->login($user->fresh());

    expect($result['mfa_required'])->toBeFalse();
    expect($result['expires_in'])->toBe(900);
});

test('a different device still requires MFA', function () {
    [, $user] = mfaUser();

    $request = requestForDevice(Str::random(64));
    app(TrustedDeviceService::class)->trust($user, $request);

    // A different browser — different cookie, different hash.
    requestForDevice(Str::random(64));
    $result = app(AuthService::class)->login($user->fresh());

    expect($result['mfa_required'])->toBeTrue();
});

test('trust expires after 30 days', function () {
    [, $user] = mfaUser();

    $deviceId = Str::random(64);
    $request = requestForDevice($deviceId);
    app(TrustedDeviceService::class)->trust($user, $request);

    expect(app(TrustedDeviceService::class)->isTrusted($user, $request))->toBeTrue();

    $this->travel(TrustedDeviceService::TRUST_DAYS + 1)->days();

    expect(app(TrustedDeviceService::class)->isTrusted($user, requestForDevice($deviceId)))->toBeFalse();
});

test('using a trusted device does not extend its expiry', function () {
    [, $user] = mfaUser();

    $deviceId = Str::random(64);
    $request = requestForDevice($deviceId);
    $device = app(TrustedDeviceService::class)->trust($user, $request);
    $originalExpiry = $device->expires_at;

    $this->travel(10)->days();
    app(TrustedDeviceService::class)->isTrusted($user, requestForDevice($deviceId));

    // Rolling renewal would make a frequently used device trusted forever, which
    // is exactly what the fixed 30-day window exists to prevent.
    expect($device->fresh()->expires_at->timestamp)->toBe($originalExpiry->timestamp);
});

test('re-trusting the same device renews rather than duplicating', function () {
    [, $user] = mfaUser();

    $deviceId = Str::random(64);

    app(TrustedDeviceService::class)->trust($user, requestForDevice($deviceId));
    $this->travel(5)->days();
    app(TrustedDeviceService::class)->trust($user, requestForDevice($deviceId));

    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(1);
});

test('a device trusted by one user does not exempt another user', function () {
    [$tenant, $userA] = mfaUser();

    $employeeB = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $userB = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employeeB->id,
        'mfa_enabled' => true,
        'mfa_secret' => encrypt(app(MfaService::class)->generateSecret()),
    ], $tenant);

    // Same physical browser, two different people.
    $deviceId = Str::random(64);
    app(TrustedDeviceService::class)->trust($userA, requestForDevice($deviceId));

    $trustedForB = app(TrustedDeviceService::class)
        ->isTrusted($userB, requestForDevice($deviceId));

    expect($trustedForB)->toBeFalse();
});

test('an unknown browser is not trusted and is not issued an identity', function () {
    [, $user] = mfaUser();

    // No cookie at all: the MFA path must not mint a device identity for an
    // unauthenticated caller.
    $request = requestForDevice(null);

    expect(app(TrustedDeviceService::class)->isTrusted($user, $request))->toBeFalse();
    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(0);
});

// ── Managing trusted devices ──

test('a user can list and revoke their trusted devices', function () {
    [$tenant, $user] = mfaUser();

    app(TrustedDeviceService::class)->trust($user, requestForDevice(Str::random(64)));

    $token = $user->createToken('auth', ['*'], now()->addMinutes(15));

    $list = test()->withToken($token->plainTextToken)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/devices");

    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);

    // The hash is the stored form of the cookie secret and must never be exposed.
    expect($list->json('data.0'))->not->toHaveKey('device_hash');

    $id = $list->json('data.0.id');

    test()->withToken($token->plainTextToken)
        ->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/devices/{$id}")
        ->assertOk();

    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(0);
});

test('a user cannot revoke another user trusted device', function () {
    [$tenant, $userA] = mfaUser();
    $userB = createUser(['role' => UserRole::EMPLOYEE], $tenant);

    $device = app(TrustedDeviceService::class)->trust($userA, requestForDevice(Str::random(64)));

    $token = $userB->createToken('auth', ['*'], now()->addMinutes(15));

    test()->withToken($token->plainTextToken)
        ->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/devices/{$device->id}")
        ->assertStatus(404);

    expect(TrustedDevice::whereKey($device->id)->exists())->toBeTrue();
});
