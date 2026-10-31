<?php

declare(strict_types=1);

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\OtpCode;
use App\Services\Auth\OtpService;
use App\Services\MfaService;
use App\Support\ThrottleIdentity;
use PragmaRX\Google2FA\Google2FA;

/*
 * Security review 2026-10-30. Three ways to guess a second factor or a
 * sign-in code at a useful rate:
 *  - /auth/mfa/verify counted no failures; only the global 300/min applied, and
 *    an accepted code could be used again inside its window;
 *  - a sign-in code (OTP) counted no failures, and the per-account limit keyed
 *    on the phone exactly as typed, so writing the number another way reset it;
 *  - the same raw key let one number empty the platform's daily SMS cap.
 */

const BRUTE_SECRET = 'JBSWY3DPEHPK3PXP';

function bruteSmsFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => $employee->id,
        'phone' => '0911223344',
        'status' => 'active',
    ], $tenant);

    $sender = new class implements SmsSender
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $to, string $message): bool
        {
            $this->sent[] = $message;

            return true;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };
    app()->instance(SmsSender::class, $sender);

    return [$tenant, $user, $sender];
}

test('five wrong MFA codes lock the challenge, even against the right code', function () {
    $user = actingAsUser([
        'role' => UserRole::TENANT_ADMIN,
        'mfa_enabled' => true,
        'mfa_secret' => BRUTE_SECRET,
    ]);
    $right = (new Google2FA)->getCurrentOtp(BRUTE_SECRET);
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, MfaService::MAX_FAILURES) as $attempt) {
        test()->postJson('/api/v1/auth/mfa/verify', ['code' => $wrong])->assertStatus(422);
    }

    test()->postJson('/api/v1/auth/mfa/verify', ['code' => $right])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect(app(MfaService::class)->lockedOut($user))->toBeTrue();
});

test('an accepted MFA code cannot finish a second sign-in', function () {
    $user = createUser(['mfa_enabled' => true, 'mfa_secret' => BRUTE_SECRET]);
    $code = (new Google2FA)->getCurrentOtp(BRUTE_SECRET);
    $mfa = app(MfaService::class);

    expect($mfa->verifyFor($user, $code, singleUse: true))->toBeTrue()
        ->and($mfa->verifyFor($user, $code, singleUse: true))->toBeFalse();
});

test('a right MFA code clears earlier failures', function () {
    $user = createUser(['mfa_enabled' => true, 'mfa_secret' => BRUTE_SECRET]);
    $right = (new Google2FA)->getCurrentOtp(BRUTE_SECRET);
    $wrong = $right === '000000' ? '111111' : '000000';
    $mfa = app(MfaService::class);

    foreach (range(1, MfaService::MAX_FAILURES - 1) as $attempt) {
        $mfa->verifyFor($user, $wrong);
    }
    expect($mfa->verifyFor($user, $right))->toBeTrue();

    foreach (range(1, MfaService::MAX_FAILURES - 1) as $attempt) {
        $mfa->verifyFor($user, $wrong);
    }
    expect($mfa->lockedOut($user))->toBeFalse();
});

test('five wrong sign-in codes spend the outstanding code and refuse the right one', function () {
    [$tenant, $user, $sender] = bruteSmsFixture();
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp";

    test()->postJson("{$base}/request", ['phone' => '0911223344'])->assertOk();
    preg_match('/\b(\d{6})\b/', $sender->sent[0], $m);
    $right = $m[1];
    $wrong = $right === '000000' ? '111111' : '000000';

    // Each attempt writes the number differently: the per-account brake is
    // the user's own failure count, whatever the spelling.
    $spellings = ['0911223344', '0911-223344', '+251911223344', '251 911 223 344', '0911 22 33 44'];
    foreach ($spellings as $phone) {
        test()->postJson("{$base}/verify", ['phone' => $phone, 'code' => $wrong])->assertStatus(422);
    }

    test()->postJson("{$base}/verify", ['phone' => '0911223344', 'code' => $right])->assertStatus(422);

    expect(OtpCode::where('user_id', $user->id)->whereNull('used_at')->count())->toBe(0);
    expect(app(OtpService::class)->verify($user->fresh(), $right))->toBeFalse();
});

test('a phone number written another way counts against the same send limit', function () {
    [$tenant] = bruteSmsFixture();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request";

    test()->postJson($url, ['phone' => '0911223344'])->assertOk();
    test()->postJson($url, ['phone' => '+251911223344'])->assertOk();
    test()->postJson($url, ['phone' => '0911-223344'])->assertOk();
    test()->postJson($url, ['phone' => '251 911 223 344'])->assertStatus(429);
});

test('the throttle identity of a phone number ignores how it is written', function (string $spelling) {
    expect(ThrottleIdentity::of($spelling))->toBe('+251911223344');
})->with(['0911223344', '0911-223344', '+251911223344', '251911223344', ' 0911 22 33 44 ']);

test('the throttle identity of an email or a username is lowercased and trimmed', function () {
    expect(ThrottleIdentity::of('  Abebe@Example.ET '))->toBe('abebe@example.et')
        ->and(ThrottleIdentity::of('EMP-0042'))->toBe('emp-0042')
        ->and(ThrottleIdentity::of(null))->toBe('');
});
