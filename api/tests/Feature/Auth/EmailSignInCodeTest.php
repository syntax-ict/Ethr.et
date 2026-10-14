<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LoginHistory;
use App\Models\OtpCode;
use App\Notifications\SignInCodeNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Sign-in codes by email: the channel every account has, used while ETHR has
 * no SMS gateway and alongside one once it does. Same endpoints, same rules
 * as the text-message codes in OtpLoginTest; only the delivery differs.
 */
function emailCodeFixture(string $mailer = 'smtp'): array
{
    config(['mail.default' => $mailer]);
    Notification::fake();

    $tenant = createTenant();
    $user = createUser([
        'role' => UserRole::EMPLOYEE,
        'email' => 'Abebe@Example.com',
        'status' => 'active',
    ], $tenant);

    return [$tenant, $user];
}

function emailedCode(object $user): string
{
    $code = null;

    Notification::assertSentTo($user, SignInCodeNotification::class, function (SignInCodeNotification $n) use ($user, &$code): bool {
        preg_match('/\b(\d{6})\b/', $n->toMail($user)->subject, $m);
        $code = $m[1] ?? null;

        return $code !== null;
    });

    return (string) $code;
}

function codeUrl(object $tenant, string $step): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/{$step}";
}

test('asking with an email address sends the code by email', function () {
    [$tenant, $user] = emailCodeFixture();

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com'])->assertOk();

    Notification::assertSentTo($user, SignInCodeNotification::class);
    expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);
});

test('the address is matched however it is capitalised', function () {
    [$tenant, $user] = emailCodeFixture();

    test()->postJson(codeUrl($tenant, 'request'), ['email' => '  ABEBE@example.COM '])->assertOk();

    Notification::assertSentTo($user, SignInCodeNotification::class);
});

test('the emailed code signs the person in', function () {
    [$tenant, $user] = emailCodeFixture();

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com']);

    test()->postJson(codeUrl($tenant, 'verify'), [
        'email' => 'abebe@example.com',
        'code' => emailedCode($user),
    ])->assertOk()->assertJsonPath('mfa_required', false);

    expect(LoginHistory::where('user_id', $user->id)->where('status', 'success')->exists())->toBeTrue();
});

test('a wrong code is refused', function () {
    [$tenant, $user] = emailCodeFixture();

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com']);

    test()->postJson(codeUrl($tenant, 'verify'), [
        'email' => 'abebe@example.com',
        'code' => emailedCode($user) === '000000' ? '111111' : '000000',
    ])->assertStatus(422);
});

test('an unknown address gets the same answer and no email', function () {
    [$tenant] = emailCodeFixture();

    $known = test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com'])->json();
    $unknown = test()->postJson(codeUrl($tenant, 'request'), ['email' => 'nobody@example.com'])->assertOk()->json();

    expect($unknown)->toBe($known);
    Notification::assertSentTimes(SignInCodeNotification::class, 1);
});

test('another organisation\'s member cannot be emailed a code from this one', function () {
    [$tenant] = emailCodeFixture();
    $other = createTenant();
    $outsider = createUser(['email' => 'outsider@example.com', 'status' => 'active'], $other);

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'outsider@example.com'])->assertOk();

    Notification::assertNotSentTo($outsider, SignInCodeNotification::class);
});

test('with no mail server it says so instead of pretending to send', function () {
    [$tenant, $user] = emailCodeFixture('log');

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com'])
        ->assertStatus(503)
        ->assertJsonPath('type', 'https://ethr.et/errors/otp-unavailable');

    Notification::assertNothingSent();
    expect(OtpCode::where('user_id', $user->id)->exists())->toBeFalse();
});

test('email and phone cannot both be given', function () {
    [$tenant] = emailCodeFixture();

    test()->postJson(codeUrl($tenant, 'request'), [
        'email' => 'abebe@example.com',
        'phone' => '0911223344',
    ])->assertStatus(422);
});

test('the sign-in page is told emailed codes are available', function () {
    [$tenant] = emailCodeFixture();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-context")
        ->assertOk()
        ->assertJsonPath('tenant.sign_in.email_code', true);

    config(['mail.default' => 'log']);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-context")
        ->assertJsonPath('tenant.sign_in.email_code', false);
});

test('an account with two-step verification still has to pass it', function () {
    [$tenant, $user] = emailCodeFixture();
    $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();

    test()->postJson(codeUrl($tenant, 'request'), ['email' => 'abebe@example.com']);

    test()->postJson(codeUrl($tenant, 'verify'), [
        'email' => 'abebe@example.com',
        'code' => emailedCode($user),
    ])->assertOk()->assertJsonPath('mfa_required', true);
});
