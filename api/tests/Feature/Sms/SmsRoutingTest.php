<?php

declare(strict_types=1);

use App\Models\SmsGateway;
use App\Models\SmsSend;
use App\Models\SmsSetting;
use App\Services\Sms\ConfiguredSmsSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * How the console's gateways carry a message: one for every number, or a
 * second for one network with the primary as its fallback; on only while
 * switched on, verified and under the daily cap.
 */
function consoleGateway(string $slot, string $endpoint, bool $verified = true): SmsGateway
{
    $gateway = new SmsGateway([
        'slot' => $slot,
        'endpoint' => $endpoint,
        'method' => 'form',
        'auth_type' => 'none',
        'recipient_field' => 'to',
        'message_field' => 'message',
        'number_format' => 'international',
        'timeout_seconds' => 5,
    ]);
    $gateway->verified_at = $verified ? now() : null;
    $gateway->save();

    return $gateway;
}

function smsOn(?string $route = null, int $cap = 500): void
{
    SmsSetting::current()->update(['enabled' => true, 'route_prefix' => $route, 'daily_cap' => $cap]);
}

function sender(): ConfiguredSmsSender
{
    return app(ConfiguredSmsSender::class);
}

test('one gateway carries every number by default', function () {
    Http::fake(['*' => Http::response('OK')]);
    consoleGateway('primary', 'https://primary.example.et/send');
    smsOn();

    expect(sender()->send('0911223344', 'a'))->toBeTrue()
        ->and(sender()->send('0711223344', 'b'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://primary.example.et'));
});

test('a Safaricom number goes to the Safaricom route, an Ethio Telecom number to the primary', function () {
    Http::fake(['*' => Http::response('OK')]);
    consoleGateway('primary', 'https://primary.example.et/send');
    consoleGateway('secondary', 'https://safaricom.example.et/send');
    smsOn(route: '7');

    sender()->send('0711223344', 'to safaricom');
    sender()->send('0911223344', 'to ethio');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://safaricom.example.et')
        && $r['to'] === '251711223344');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://primary.example.et')
        && $r['to'] === '251911223344');
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://safaricom.example.et')
        && $r['to'] === '251911223344');
});

test('a routed number falls back to the primary when its gateway fails', function () {
    Http::fake([
        'safaricom.example.et/*' => Http::response('down', 503),
        'primary.example.et/*' => Http::response('OK'),
    ]);
    consoleGateway('primary', 'https://primary.example.et/send');
    consoleGateway('secondary', 'https://safaricom.example.et/send');
    smsOn(route: '7');

    expect(sender()->send('0711223344', 'hello'))->toBeTrue();

    Http::assertSentCount(2);
    expect(SmsSend::query()->value('slot'))->toBe('primary');
});

test('an unverified route is not used', function () {
    Http::fake(['*' => Http::response('OK')]);
    consoleGateway('primary', 'https://primary.example.et/send');
    consoleGateway('secondary', 'https://safaricom.example.et/send', verified: false);
    smsOn(route: '7');

    sender()->send('0711223344', 'hello');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'safaricom'));
});

test('a landline is refused and nothing is sent', function () {
    Http::fake();
    consoleGateway('primary', 'https://primary.example.et/send');
    smsOn();

    expect(sender()->send('0111223344', 'hello'))->toBeFalse();
    Http::assertNothingSent();
});

test('SMS is off until switched on and verified', function () {
    consoleGateway('primary', 'https://primary.example.et/send', verified: false);

    expect(sender()->isAvailable())->toBeFalse();

    SmsSetting::current()->update(['enabled' => true]);
    expect(sender()->isAvailable())->toBeFalse();

    SmsGateway::query()->update(['verified_at' => now()]);
    expect(sender()->isAvailable())->toBeTrue();
});

test('reaching the daily cap makes SMS unavailable and stops sending', function () {
    Http::fake(['*' => Http::response('OK')]);
    consoleGateway('primary', 'https://primary.example.et/send');
    smsOn(cap: 2);

    expect(sender()->send('0911223344', 'one'))->toBeTrue()
        ->and(sender()->send('0911223344', 'two'))->toBeTrue()
        ->and(sender()->isAvailable())->toBeFalse()
        ->and(sender()->send('0911223344', 'three'))->toBeFalse();

    Http::assertSentCount(2);
    expect(SmsSend::countToday())->toBe(2);
});

test('the cap counts the database, so a cache flush does not reset it', function () {
    Http::fake(['*' => Http::response('OK')]);
    consoleGateway('primary', 'https://primary.example.et/send');
    smsOn(cap: 1);

    sender()->send('0911223344', 'one');
    Cache::flush();

    expect(sender()->isAvailable())->toBeFalse();
});

test('a rejected message is not counted against the cap', function () {
    Http::fake(['*' => Http::response('nope', 400)]);
    consoleGateway('primary', 'https://primary.example.et/send');
    smsOn();

    expect(sender()->send('0911223344', 'hello'))->toBeFalse();
    expect(SmsSend::countToday())->toBe(0);
});

test('once the console has a gateway, the .env gateway no longer applies', function () {
    config([
        'sms.driver' => 'ethiotelecom',
        'sms.ethiotelecom' => ['endpoint' => 'https://env.example.et/send', 'username' => 'u', 'password' => 'p'],
    ]);
    expect(sender()->isAvailable())->toBeTrue();

    consoleGateway('primary', 'https://primary.example.et/send', verified: false);

    expect(sender()->isAvailable())->toBeFalse();
});
