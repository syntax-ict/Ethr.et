<?php

declare(strict_types=1);

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsNumber;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PHASE_05 S26 refers to the `SmsSender` interface as "existing". It did not
 * exist, while the preferences API offered an `sms` channel — so a user could opt
 * into a channel with no delivery path at all behind it.
 */
class FakeSmsSender implements SmsSender
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

class SmsTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): string
    {
        return 'Your payroll is ready.';
    }
}

// ── Number normalization ──

test('Ethiopian numbers normalize to E.164 digits regardless of input shape', function () {
    expect(SmsNumber::normalize('0911223344'))->toBe('251911223344');
    expect(SmsNumber::normalize('+251911223344'))->toBe('251911223344');
    expect(SmsNumber::normalize('251 911 22 33 44'))->toBe('251911223344');
    expect(SmsNumber::normalize('911223344'))->toBe('251911223344');
});

test('validation accepts Ethiopian mobile prefixes and rejects others', function () {
    expect(SmsNumber::isValid('0911223344'))->toBeTrue();
    expect(SmsNumber::isValid('0711223344'))->toBeTrue();
    expect(SmsNumber::isValid('0111223344'))->toBeFalse();
    expect(SmsNumber::isValid('12345'))->toBeFalse();
});

// ── Channel behaviour ──

test('the sms channel delivers a message through the configured sender', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id, 'phone' => '0911223344'],
        $tenant,
    );

    $sender = new FakeSmsSender;
    (new SmsChannel($sender))->send($user, new SmsTestNotification);

    expect($sender->sent)->toHaveCount(1);
    expect($sender->sent[0]['message'])->toBe('Your payroll is ready.');
});

test('a user with no phone number is skipped rather than erroring', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id, 'phone' => null],
        $tenant,
    );

    $sender = new FakeSmsSender;
    (new SmsChannel($sender))->send($user, new SmsTestNotification);

    expect($sender->sent)->toBe([]);
});

test('the daily cap of five messages per user is enforced', function () {
    Cache::flush();

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id, 'phone' => '0911223344'],
        $tenant,
    );

    $sender = new FakeSmsSender;
    $channel = new SmsChannel($sender);

    for ($i = 0; $i < 8; $i++) {
        $channel->send($user, new SmsTestNotification);
    }

    expect($sender->sent)->toHaveCount(5);
});

// ── Driver availability ──

test('the log driver reports itself unavailable so the UI does not offer SMS', function () {
    expect((new LogSmsSender)->isAvailable())->toBeFalse();
});

// The `.env` gateway (SMS_DRIVER=ethiotelecom + ETHIOTELECOM_SMS_*) is the
// fallback while no gateway is saved in the console. The class that sent it,
// EthioTelecomSmsSender, is gone: the same form post is now configuration for
// the one generic HTTP gateway (see SmsGatewayTest).
function envGateway(array $overrides = []): void
{
    config([
        'sms.driver' => 'ethiotelecom',
        'sms.ethiotelecom' => array_merge([
            'endpoint' => 'https://sms.example.et/send',
            'username' => 'u',
            'password' => 'p',
            'sender_id' => 'ETHR',
            'timeout' => 5,
        ], $overrides),
    ]);
}

test('the .env gateway is unavailable until it is configured', function () {
    envGateway(['endpoint' => null, 'username' => null, 'password' => null]);
    expect(app(ConfiguredSmsSender::class)->isAvailable())->toBeFalse();

    envGateway();
    expect(app(ConfiguredSmsSender::class)->isAvailable())->toBeTrue();
});

test('an unconfigured .env gateway refuses to send instead of pretending', function () {
    Http::fake();
    envGateway(['endpoint' => null, 'username' => null, 'password' => null]);

    expect(app(ConfiguredSmsSender::class)->send('0911223344', 'hello'))->toBeFalse();
    Http::assertNothingSent();
});

test('a configured .env gateway posts the same form it always did', function () {
    Http::fake(['*' => Http::response('OK', 200)]);
    envGateway();

    expect(app(ConfiguredSmsSender::class)->send('0911223344', 'hello'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://sms.example.et/send'
        && $request['username'] === 'u'
        && $request['password'] === 'p'
        && $request['from'] === 'ETHR'
        && $request['to'] === '251911223344'
        && $request['text'] === 'hello');
});

// ── Client-facing availability ──

test('the preferences endpoint reports whether SMS can actually be delivered', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications/preferences");

    $response->assertOk();
    // Default driver is `log`, which cannot deliver.
    expect($response->json('channel_availability.sms'))->toBeFalse();
    expect($response->json('channel_availability.email'))->toBeTrue();
});
