<?php

declare(strict_types=1);

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\EthioTelecomSmsSender;
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

test('the EthioTelecom driver is unavailable until it is configured', function () {
    config(['sms.ethiotelecom' => ['endpoint' => null, 'username' => null, 'password' => null]]);
    expect((new EthioTelecomSmsSender)->isAvailable())->toBeFalse();

    config(['sms.ethiotelecom' => [
        'endpoint' => 'https://sms.example.et/send',
        'username' => 'u',
        'password' => 'p',
        'sender_id' => 'ETHR',
        'timeout' => 5,
    ]]);
    expect((new EthioTelecomSmsSender)->isAvailable())->toBeTrue();
});

test('an unconfigured EthioTelecom driver refuses to send instead of pretending', function () {
    config(['sms.ethiotelecom' => ['endpoint' => null, 'username' => null, 'password' => null]]);

    expect((new EthioTelecomSmsSender)->send('0911223344', 'hello'))->toBeFalse();
});

test('a configured EthioTelecom driver posts a normalized number to the gateway', function () {
    Http::fake(['*' => Http::response('OK', 200)]);

    config(['sms.ethiotelecom' => [
        'endpoint' => 'https://sms.example.et/send',
        'username' => 'u',
        'password' => 'p',
        'sender_id' => 'ETHR',
        'timeout' => 5,
    ]]);

    expect((new EthioTelecomSmsSender)->send('0911223344', 'hello'))->toBeTrue();

    Http::assertSent(fn ($request) => $request['to'] === '251911223344' && $request['text'] === 'hello');
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
