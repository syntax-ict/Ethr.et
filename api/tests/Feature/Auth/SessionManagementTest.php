<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Resources\SessionResource;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Services\Auth\DeviceFingerprint;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PHASE_00 S03 — active sessions, and S02 — login history.
 *
 * Before this, `login()` deleted every token on each sign-in, so a user could
 * only ever hold one session and there was nothing for a sessions list to show.
 * `LoginHistory::record()` existed but no caller ever invoked it.
 */
function sessionFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'password' => bcrypt('password'),
    ], $tenant);

    return [$tenant, $user];
}

// ── Login history ──

test('a successful sign-in is recorded in login history', function () {
    [$tenant, $user] = sessionFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $history = LoginHistory::withoutGlobalScopes()->where('user_id', $user->id)->get();

    expect($history)->toHaveCount(1);
    expect($history->first()->status)->toBe('success');
    expect($history->first()->ip_address)->not->toBeNull();
});

test('a failed sign-in is recorded with its reason', function () {
    [$tenant, $user] = sessionFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);

    $history = LoginHistory::withoutGlobalScopes()->where('user_id', $user->id)->get();

    expect($history)->toHaveCount(1);
    expect($history->first()->status)->toBe('failed');
    expect($history->first()->failure_reason)->toBe('invalid_password');
});

test('an unknown identifier writes no history row', function () {
    [$tenant] = sessionFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => 'nobody@example.et',
        'password' => 'whatever',
    ])->assertStatus(422);

    // Otherwise the history becomes a log of guessed usernames.
    expect(LoginHistory::withoutGlobalScopes()->count())->toBe(0);
});

test('a sign-in blocked for an inactive account is recorded', function () {
    [$tenant, $user] = sessionFixture();
    $user->update(['status' => 'suspended']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(403);

    $history = LoginHistory::withoutGlobalScopes()->where('user_id', $user->id)->first();

    expect($history?->status)->toBe('blocked');
    expect($history?->failure_reason)->toBe('account_inactive');
});

// ── Sessions ──

test('the sessions list shows the current session and names the device', function () {
    [$tenant, $user] = sessionFixture();

    // Authenticate with a real bearer token, not actingAs(): Sanctum returns a
    // TransientToken for session auth, which is not a stored session and so
    // cannot be listed or marked current.
    $token = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $token->accessToken->forceFill([
        'ip_address' => '10.1.2.3',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36',
    ])->save();

    $response = test()->withToken($token->plainTextToken)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions");

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
    expect($response->json('data.0'))->toHaveKeys(['id', 'device', 'ip_address', 'last_used_at', 'is_current']);
    expect($response->json('data.0.device'))->toBe('Chrome on Windows');
    expect($response->json('data.0.is_current'))->toBeTrue();
});

test('signing in on a second device does not sign the first one out', function () {
    [$tenant, $user] = sessionFixture();

    $first = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $first->accessToken->forceFill(['device_hash' => 'device-one'])->save();

    // A different browser: distinct device cookie, so a distinct device hash.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    // The pre-existing session on the other device survives.
    expect($user->tokens()->whereKey($first->accessToken->getKey())->exists())->toBeTrue();
    expect($user->tokens()->count())->toBeGreaterThan(1);
});

test('signing in twice on the same device replaces that device session', function () {
    [$tenant, $user] = sessionFixture();

    app(CurrentTenant::class)->set($tenant);

    // Driven through AuthService rather than the HTTP endpoint: the device
    // identity lives in a cookie, and the framework's test client re-encrypts
    // cookies per request (fresh IV each time), so the same browser cannot be
    // represented across two HTTP calls. Binding the request directly is the only
    // way to express "the same device signs in twice" honestly.
    $deviceId = Str::random(64);

    $signIn = function () use ($deviceId, $user) {
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->cookies->set(DeviceFingerprint::COOKIE, $deviceId);
        app()->instance('request', $request);

        app(AuthService::class)->login($user->fresh());
    };

    $signIn();
    $afterFirst = $user->tokens()->count();
    $firstHash = $user->tokens()->latest('id')->first()->device_hash;

    $signIn();

    // Same device hash → the previous session for this browser was replaced, not
    // stacked, so token rows cannot grow without bound on repeat sign-ins.
    expect($user->tokens()->count())->toBe($afterFirst);
    expect($user->tokens()->latest('id')->first()->device_hash)->toBe($firstHash);
});

test('a session can be revoked', function () {
    [$tenant, $user] = sessionFixture();

    $caller = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $other = $user->createToken('auth', ['*'], now()->addMinutes(15));

    test()->withToken($caller->plainTextToken)
        ->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions/{$other->accessToken->getKey()}")
        ->assertOk()
        ->assertJsonPath('was_current', false);

    expect($user->tokens()->whereKey($other->accessToken->getKey())->exists())->toBeFalse();
    expect($user->tokens()->whereKey($caller->accessToken->getKey())->exists())->toBeTrue();
});

test('revoke-all keeps the calling session alive', function () {
    [$tenant, $user] = sessionFixture();

    $caller = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $user->createToken('auth', ['*'], now()->addMinutes(15));
    $user->createToken('auth', ['*'], now()->addMinutes(15));

    $response = test()->withToken($caller->plainTextToken)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions/revoke-all");

    $response->assertOk();
    expect($response->json('revoked'))->toBe(2);

    // The caller is still signed in — this is the "secure my account" control,
    // not a self-logout.
    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->whereKey($caller->accessToken->getKey())->exists())->toBeTrue();
});

test('a user cannot revoke another user session', function () {
    [$tenant, $user] = sessionFixture();
    $other = createUser(['role' => UserRole::EMPLOYEE], $tenant);
    $victimToken = $other->createToken('auth', ['*'], now()->addMinutes(15));

    test()->actingAs($user);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions/{$victimToken->accessToken->getKey()}")
        ->assertStatus(404);

    expect($other->tokens()->count())->toBe(1);
});

test('expired sessions are not listed as active', function () {
    [$tenant, $user] = sessionFixture();

    $expired = $user->createToken('auth', ['*'], now()->subMinute());

    test()->actingAs($user);

    $ids = collect(test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions")->json('data'))
        ->pluck('id');

    expect($ids)->not->toContain($expired->accessToken->getKey());
});

test('the current device is identified under session-cookie auth too', function () {
    [$tenant, $user] = sessionFixture();

    // The app authenticates statefully, so `currentAccessToken()` is a
    // TransientToken with no id. Without the device-hash fallback nothing would
    // ever be marked "This device" in the real UI.
    $deviceId = Str::random(64);
    $token = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $token->accessToken->forceFill([
        'device_hash' => hash('sha256', $deviceId),
    ])->save();

    // Built directly rather than through the HTTP client, which re-encrypts
    // cookies per request and so cannot represent a specific browser.
    $request = Request::create('/api/v1/auth/sessions', 'GET');
    $request->cookies->set(DeviceFingerprint::COOKIE, $deviceId);
    $request->setUserResolver(fn () => $user);

    $payload = (new SessionResource($token->accessToken->fresh()))->resolve($request);

    expect($payload['is_current'])->toBeTrue();
});

test('a different browser is not marked as the current device', function () {
    [$tenant, $user] = sessionFixture();

    $token = $user->createToken('auth', ['*'], now()->addMinutes(15));
    $token->accessToken->forceFill([
        'device_hash' => hash('sha256', Str::random(64)),
    ])->save();

    $request = Request::create('/api/v1/auth/sessions', 'GET');
    $request->cookies->set(DeviceFingerprint::COOKIE, Str::random(64));
    $request->setUserResolver(fn () => $user);

    $payload = (new SessionResource($token->accessToken->fresh()))->resolve($request);

    expect($payload['is_current'])->toBeFalse();
});

test('sessions require authentication', function () {
    [$tenant] = sessionFixture();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/sessions")->assertStatus(401);
});
