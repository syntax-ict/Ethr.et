<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\User;
use App\Notifications\SmsGatewayPausedNotification;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\Sms\HttpSmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What a gateway does with real messages: a refusal is recorded without the
 * recipient's number, enough of them in a row take SMS off the sign-in page,
 * and a test or a new configuration brings it back. Fakes only; what a real
 * provider answers is proven on the console's test button.
 */
const LIVE_SMS_ADMIN = 'http://admin.ethr.test/api/v1/admin/sms';

const LIVE_REFUSAL = '{"acknowledge":"error","response":{"errors":["+251911223344 is unverified contact number"]}}';

function liveGateway(string $slot = 'primary', string $endpoint = 'https://primary.example.et/send'): SmsGateway
{
    $gateway = new SmsGateway([
        'slot' => $slot,
        'label' => 'AfroMessage',
        'endpoint' => $endpoint,
        'method' => 'json',
        'auth_type' => 'none',
        'recipient_field' => 'to',
        'message_field' => 'message',
        'number_format' => 'e164',
        'success_json_path' => 'acknowledge',
        'success_json_value' => 'success',
        'timeout_seconds' => 5,
    ]);
    $gateway->verified_at = now();
    $gateway->save();

    return $gateway;
}

function liveSmsOn(?string $route = null): void
{
    SmsSetting::current()->update(['enabled' => true, 'route_prefix' => $route, 'daily_cap' => 500]);
}

function liveSender(): ConfiguredSmsSender
{
    return app(ConfiguredSmsSender::class);
}

function refuseTimes(int $times, string $to = '0911223344'): void
{
    for ($i = 0; $i < $times; $i++) {
        expect(liveSender()->send($to, 'Your code is 123456'))->toBeFalse();
    }
}

function liveSuperAdmin(): User
{
    return User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'status' => 'active',
        'mfa_enabled' => true,
    ]);
}

function liveRequestsTo(string $host): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), $host))->count();
}

/** The gateway's answer, changeable mid-test: a second Http::fake would not replace the first. */
function liveAnswer(array|string $body): void
{
    $GLOBALS['live_answer'] = $body;
    Http::fake(fn () => Http::response($GLOBALS['live_answer'], 200));
}

beforeEach(function () {
    RateLimiter::clear('sms-test');
    Notification::fake();
});

test('a live refusal is recorded without the number, and SMS stays on under the threshold', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();

    refuseTimes(1);

    $gateway = SmsGateway::query()->where('slot', 'primary')->firstOrFail();
    expect($gateway->consecutive_failures)->toBe(1)
        ->and($gateway->last_failure_status)->toBe(200)
        ->and($gateway->last_failure_at)->not->toBeNull()
        ->and($gateway->last_failure_response)->toContain('unverified contact number')
        ->and($gateway->last_failure_response)->toContain('[number]')
        ->and($gateway->last_failure_response)->not->toContain('911223344')
        ->and($gateway->paused_until)->toBeNull()
        ->and(liveSender()->isAvailable())->toBeTrue();

    Notification::assertNothingSent();
});

test('five refusals in a row pause the primary, tell each super admin once and audit without the number', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();
    $first = liveSuperAdmin();
    $second = liveSuperAdmin();

    refuseTimes(SmsGateway::PAUSE_AFTER_FAILURES);

    expect(liveSender()->isAvailable())->toBeFalse();

    $sent = count(Http::recorded());
    expect(liveSender()->send('0911223344', 'Your code is 123456'))->toBeFalse();
    Http::assertSentCount($sent);

    Notification::assertSentTo([$first, $second], SmsGatewayPausedNotification::class);
    Notification::assertSentTimes(SmsGatewayPausedNotification::class, 2);

    $audits = AuditLog::withoutGlobalScopes()->where('action', 'platform.sms_gateway_paused')->get();
    expect($audits)->toHaveCount(1)
        ->and(json_encode($audits->first()->payload))->not->toContain('911223344')
        ->and($audits->first()->payload)->toBe(['slot' => 'primary', 'failures' => 5, 'status' => 200]);
});

test('the pause mail names the gateway, the answer and when SMS returns, without the number', function () {
    $admin = liveSuperAdmin();
    $notification = new SmsGatewayPausedNotification(
        'primary', 'AfroMessage', 5, 200, '[number] is unverified contact number', now()->addMinutes(15)->toIso8601String(),
    );

    $mail = $notification->toMail($admin);
    $text = implode("\n", [$mail->subject, ...$mail->introLines]);

    expect($text)->toContain('AfroMessage')
        ->and($text)->toContain('5')
        ->and($text)->toContain('HTTP 200')
        ->and($text)->toContain('unverified contact number')
        ->and($text)->not->toContain('911223344')
        ->and($mail->actionUrl)->toContain('/admin/sms')
        ->and($notification->toArray($admin)['url'])->toBe('/admin/sms');
});

test('after the cooldown SMS is offered again, a further refusal pauses it silently, a success resets it', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();
    liveSuperAdmin();

    refuseTimes(SmsGateway::PAUSE_AFTER_FAILURES);
    expect(liveSender()->isAvailable())->toBeFalse();

    $this->travel(SmsGateway::PAUSE_MINUTES + 1)->minutes();
    expect(liveSender()->isAvailable())->toBeTrue();

    refuseTimes(1);
    $gateway = SmsGateway::query()->where('slot', 'primary')->firstOrFail();
    expect($gateway->consecutive_failures)->toBe(6)
        ->and($gateway->isPaused())->toBeTrue()
        ->and(liveSender()->isAvailable())->toBeFalse();
    Notification::assertSentTimes(SmsGatewayPausedNotification::class, 1);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'platform.sms_gateway_paused')->count())->toBe(1);

    $this->travel(SmsGateway::PAUSE_MINUTES + 1)->minutes();
    liveAnswer(['acknowledge' => 'success']);

    expect(liveSender()->send('0911223344', 'Your code is 123456'))->toBeTrue();

    $gateway->refresh();
    expect($gateway->consecutive_failures)->toBe(0)
        ->and($gateway->paused_until)->toBeNull()
        ->and($gateway->last_success_at)->not->toBeNull()
        ->and(liveSender()->isAvailable())->toBeTrue();
});

test('a paused secondary sends routed numbers straight to the primary', function () {
    Http::fake([
        'secondary.example.et/*' => Http::response(LIVE_REFUSAL, 200),
        'primary.example.et/*' => Http::response(['acknowledge' => 'success'], 200),
    ]);
    liveGateway('primary', 'https://primary.example.et/send');
    liveGateway('secondary', 'https://secondary.example.et/send');
    liveSmsOn('9');

    for ($i = 0; $i < SmsGateway::PAUSE_AFTER_FAILURES; $i++) {
        expect(liveSender()->send('0911223344', 'Your code is 123456'))->toBeTrue();
    }

    expect(SmsGateway::query()->where('slot', 'secondary')->firstOrFail()->isPaused())->toBeTrue()
        ->and(SmsGateway::query()->where('slot', 'primary')->firstOrFail()->isPaused())->toBeFalse()
        ->and(liveSender()->isAvailable())->toBeTrue();

    $secondaryCalls = liveRequestsTo('secondary.example.et');
    expect($secondaryCalls)->toBe(5);

    expect(liveSender()->send('0911223344', 'Your code is 123456'))->toBeTrue()
        ->and(liveRequestsTo('secondary.example.et'))->toBe($secondaryCalls);
});

test('a successful console test clears a pause, and a failed one leaves the live counters alone', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();
    $operator = liveSuperAdmin();
    $this->actingAs($operator);

    refuseTimes(SmsGateway::PAUSE_AFTER_FAILURES);

    $this->postJson(LIVE_SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])
        ->assertOk()
        ->assertJsonPath('ok', false);

    $gateway = SmsGateway::query()->where('slot', 'primary')->firstOrFail();
    expect($gateway->consecutive_failures)->toBe(5)
        ->and($gateway->isPaused())->toBeTrue();

    liveAnswer(['acknowledge' => 'success']);

    $this->postJson(LIVE_SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $gateway->refresh();
    expect($gateway->consecutive_failures)->toBe(0)
        ->and($gateway->paused_until)->toBeNull()
        ->and($gateway->last_success_at)->not->toBeNull()
        ->and(liveSender()->isAvailable())->toBeTrue();
});

test('editing how the gateway connects clears the counters, editing the label does not', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();
    $this->actingAs(liveSuperAdmin());

    refuseTimes(SmsGateway::PAUSE_AFTER_FAILURES);

    $payload = [
        'label' => 'Renamed',
        'endpoint' => 'https://primary.example.et/send',
        'method' => 'json',
        'auth_type' => 'none',
        'recipient_field' => 'to',
        'message_field' => 'message',
        'number_format' => 'e164',
        'success_json_path' => 'acknowledge',
        'success_json_value' => 'success',
    ];

    $this->putJson(LIVE_SMS_ADMIN.'/gateways/primary', $payload)->assertOk();
    expect(SmsGateway::query()->where('slot', 'primary')->firstOrFail()->consecutive_failures)->toBe(5);

    $this->putJson(LIVE_SMS_ADMIN.'/gateways/primary', [...$payload, 'endpoint' => 'https://other.example.et/send'])
        ->assertOk()
        ->assertJsonPath('primary.consecutive_failures', 0)
        ->assertJsonPath('primary.paused', false);

    $gateway = SmsGateway::query()->where('slot', 'primary')->firstOrFail();
    expect($gateway->consecutive_failures)->toBe(0)
        ->and($gateway->paused_until)->toBeNull();
});

test('the console reports live health, with no secret and no raw number', function () {
    liveAnswer(LIVE_REFUSAL);
    liveGateway();
    liveSmsOn();
    $this->actingAs(liveSuperAdmin());

    refuseTimes(SmsGateway::PAUSE_AFTER_FAILURES);

    $response = $this->getJson(LIVE_SMS_ADMIN)
        ->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('primary.consecutive_failures', 5)
        ->assertJsonPath('primary.paused', true)
        ->assertJsonPath('primary.last_failure_status', 200)
        ->assertJsonPath('primary.last_success_at', null)
        ->assertJsonMissingPath('primary.auth_secret');

    $body = (string) $response->getContent();
    expect($body)->not->toContain('911223344')
        ->and($body)->toContain('unverified contact number');

    $primary = $response->json('primary');
    expect($primary['paused_until'])->toBeString()
        ->and($primary['last_failure_at'])->toBeString();
});

test('a refusal from the .env gateway, which has no row, does not error', function () {
    config([
        'sms.driver' => 'ethiotelecom',
        'sms.ethiotelecom' => [
            'endpoint' => 'https://env.example.et/send',
            'username' => 'envuser',
            'password' => 'envpassword',
            'sender_id' => 'ETHR',
        ],
    ]);
    Http::fake(['*' => Http::response('rejected', 500)]);

    expect(liveSender()->send('0911223344', 'Your code is 123456'))->toBeFalse()
        ->and(SmsGateway::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('the recipient is taken out of a response in every form', function () {
    $http = new HttpSmsGateway(liveGateway());
    $uuid = '3f2a9b1c-7d44-4e8a-9c01-9112233445ab';

    foreach (['+251911223344', '251911223344', '0911223344', '911223344'] as $form) {
        expect($http->withoutRecipient("rejected {$form} now", '0911223344'))->toBe('rejected [number] now');
    }

    expect($http->withoutRecipient('sent to 09 11 22 33 44?', ' 09 11 22 33 44 '))->toBe('sent to [number]?');

    expect($http->withoutRecipient("id {$uuid} for +251722334455 and 0933445566", '0911223344'))
        ->toBe("id {$uuid} for [number] and [number]");

    expect($http->withoutRecipient('order 19112233445 failed', '0911223344'))->toBe('order 19112233445 failed');
});
