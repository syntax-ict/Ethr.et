<?php

declare(strict_types=1);

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\OtpCode;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\Hash;

/**
 * PHASE_00 S02 OTP sign-in. The `otp_codes` table shipped in the initial
 * migration and was never used: no model, no service, no routes — while
 * `RateLimiter::for('otp')` guarded endpoints that did not exist.
 */
class RecordingSmsSender implements SmsSender
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $available = true) {}

    public function send(string $to, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}

function otpFixture(bool $smsAvailable = true): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'phone' => '0911223344',
        'status' => 'active',
    ], $tenant);

    $sender = new RecordingSmsSender($smsAvailable);
    app()->instance(SmsSender::class, $sender);

    return [$tenant, $user, $sender];
}

/** Pull the code out of the message the gateway received. */
function codeFrom(RecordingSmsSender $sender): string
{
    preg_match('/\b(\d{6})\b/', $sender->sent[0]['message'], $m);

    return $m[1];
}

// ── Requesting ──

test('requesting a code sends an SMS', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    expect($sender->sent)->toHaveCount(1);
    expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);
});

test('the code is stored hashed, never in plaintext', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    $code = codeFrom($sender);
    $stored = OtpCode::where('user_id', $user->id)->first();

    expect($stored->code)->not->toBe($code);
    expect(Hash::check($code, $stored->code))->toBeTrue();
});

test('an unknown phone gets the same response as a known one', function () {
    [$tenant, , $sender] = otpFixture();

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911999999',
    ]);

    $response->assertOk();
    // No enumeration signal, and nothing sent.
    expect($sender->sent)->toBe([]);
});

test('the endpoint reports unavailability instead of pretending to send', function () {
    [$tenant] = otpFixture(smsAvailable: false);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertStatus(503);
});

test('requesting a new code invalidates the previous one', function () {
    [$tenant, $user, $sender] = otpFixture();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request";

    test()->postJson($url, ['phone' => '0911223344'])->assertOk();
    $firstCode = codeFrom($sender);

    test()->postJson($url, ['phone' => '0911223344'])->assertOk();

    // The superseded code must no longer work, or requesting a second code
    // would widen rather than reset the guessing surface.
    expect(app(OtpService::class)->verify($user->fresh(), $firstCode))->toBeFalse();
});

// ── Verifying ──

test('a valid code signs the user in', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => codeFrom($sender),
    ]);

    $response->assertOk();
    expect($response->json('access_token'))->not->toBeNull();

    expect(LoginHistory::withoutGlobalScopes()
        ->where('user_id', $user->id)->where('status', 'success')->count())->toBe(1);
});

test('verifying a code actually authenticates the session, not just the token', function () {
    // The browser SPA carries no Authorization header — every request after
    // login runs on the session cookie LoginRequest::authenticate() establishes
    // via Auth::login(). OtpController::verify() only issued a Sanctum token
    // and never called Auth::login(), so a successful OTP response looked fine
    // but left the browser unauthenticated for every request that followed it.
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => codeFrom($sender),
    ])->assertOk();

    test()->assertAuthenticatedAs($user);
});

test('a wrong code is rejected and recorded', function () {
    [$tenant, $user] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => '000000',
    ])->assertStatus(422);

    expect(LoginHistory::withoutGlobalScopes()
        ->where('user_id', $user->id)->where('failure_reason', 'invalid_otp')->count())->toBe(1);
});

test('a code cannot be reused', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();
    $code = codeFrom($sender);

    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify";

    test()->postJson($url, ['phone' => '0911223344', 'code' => $code])->assertOk();
    test()->postJson($url, ['phone' => '0911223344', 'code' => $code])->assertStatus(422);
});

test('an expired code is rejected', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();
    $code = codeFrom($sender);

    test()->travel(OtpService::TTL_MINUTES + 1)->minutes();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => $code,
    ])->assertStatus(422);
});

test('a suspended account cannot sign in by OTP', function () {
    [$tenant, $user, $sender] = otpFixture();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();
    $code = codeFrom($sender);

    $user->update(['status' => 'suspended']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => $code,
    ])->assertStatus(403);
});

test('a code issued for one tenant does not work against another', function () {
    [$tenantA, , $sender] = otpFixture();

    test()->postJson("http://{$tenantA->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();
    $code = codeFrom($sender);

    $tenantB = createTenant();

    test()->postJson("http://{$tenantB->subdomain}.ethr.test/api/v1/auth/otp/verify", [
        'phone' => '0911223344',
        'code' => $code,
    ])->assertStatus(422);
});

// ── Phone format tolerance (roadmap 4.1) ──

// The bug these pin: `resolveUser()` matched the raw input against
// `users.phone` exactly. Registration and UserFactory store E.164
// (`+251911223344`) while users type the local form (`0911223344`), so the
// lookup found nobody — and since these endpoints answer identically for
// unknown numbers, the caller got "check your phone" and waited for a code that
// was never issued. Every pre-existing test here stored *and* queried the same
// local format, so the suite never crossed the two shapes.
//
// Asserting on the persisted OtpCode, not the status: the response is 200
// either way by design, so status assertions cannot see this failure at all.

$otpPhoneFixture = function (string $storedPhone) {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'phone' => $storedPhone,
        'status' => 'active',
    ], $tenant);

    $sender = new RecordingSmsSender(true);
    app()->instance(SmsSender::class, $sender);

    return [$tenant, $user];
};

test('a user stored in E.164 can request a code using the local format', function () use ($otpPhoneFixture) {
    [$tenant, $user] = $otpPhoneFixture('+251911223344');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911223344',
    ])->assertOk();

    expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);
});

test('a user stored in the local format can request a code using E.164', function () use ($otpPhoneFixture) {
    [$tenant, $user] = $otpPhoneFixture('0911223344');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '+251911223344',
    ])->assertOk();

    expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);
});

test('a genuinely unknown number still issues nothing', function () use ($otpPhoneFixture) {
    [$tenant, $user] = $otpPhoneFixture('+251911223344');

    // Guards the widened lookup: tolerating formats must not tolerate *numbers*.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", [
        'phone' => '0911999999',
    ])->assertOk();

    expect(OtpCode::where('user_id', $user->id)->count())->toBe(0);
});
