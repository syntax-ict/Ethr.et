<?php

declare(strict_types=1);

use App\Services\Sms\EthioTelecomSmsSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('sms.ethiotelecom', [
        'endpoint' => 'https://sms.ethiotelecom.et/api/send',
        'username' => 'ethr',
        'password' => 'secret',
        'sender_id' => 'ETHR',
        'timeout' => 10,
    ]);
});

it('reports unavailable until endpoint and credentials are configured', function () {
    config()->set('sms.ethiotelecom.endpoint', null);

    expect((new EthioTelecomSmsSender)->isAvailable())->toBeFalse();
});

it('reports available once fully configured', function () {
    expect((new EthioTelecomSmsSender)->isAvailable())->toBeTrue();
});

it('posts the operator form shape with a normalized recipient', function () {
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $ok = (new EthioTelecomSmsSender)->send('0911223344', 'Your code is 123456');

    expect($ok)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://sms.ethiotelecom.et/api/send'
            && $request['username'] === 'ethr'
            && $request['password'] === 'secret'
            && $request['from'] === 'ETHR'
            && $request['to'] === '251911223344'   // normalized, no plus/zero
            && $request['text'] === 'Your code is 123456';
    });
});

it('returns false when the gateway rejects the message', function () {
    Http::fake([
        '*' => Http::response('Denied', 403),
    ]);

    expect((new EthioTelecomSmsSender)->send('0911223344', 'hi'))->toBeFalse();
});

it('returns false and does not call the gateway for an invalid number', function () {
    Http::fake();

    expect((new EthioTelecomSmsSender)->send('0111223344', 'hi'))->toBeFalse();

    Http::assertNothingSent();
});

it('returns false when the gateway request throws', function () {
    Http::fake(function () {
        throw new RuntimeException('connection reset');
    });

    expect((new EthioTelecomSmsSender)->send('0911223344', 'hi'))->toBeFalse();
});

it('does not attempt delivery when unconfigured', function () {
    config()->set('sms.ethiotelecom.password', null);
    Http::fake();

    expect((new EthioTelecomSmsSender)->send('0911223344', 'hi'))->toBeFalse();

    Http::assertNothingSent();
});
