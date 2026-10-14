<?php

declare(strict_types=1);

use App\Models\SmsGateway;
use App\Services\Sms\HttpSmsGateway;
use App\Services\Sms\SmsGatewayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The one generic HTTP gateway, against fakes only. What a real provider
 * accepts can only be proven on subscription day, with the console's test
 * button against the real gateway.
 */
function gatewayConfig(array $overrides = []): SmsGateway
{
    return new SmsGateway(array_merge([
        'slot' => 'primary',
        'endpoint' => 'https://sms.example.et/send',
        'method' => 'form',
        'auth_type' => 'none',
        'recipient_field' => 'to',
        'message_field' => 'message',
        'number_format' => 'international',
        'timeout_seconds' => 5,
    ], $overrides));
}

function sendWith(array $overrides, string $to = '0911223344'): SmsGatewayResult
{
    return (new HttpSmsGateway(gatewayConfig($overrides)))->send($to, 'Your code is 123456');
}

// ── Body formats ──

test('posts a form', function () {
    Http::fake(['*' => Http::response('OK')]);

    expect(sendWith(['method' => 'form'])->ok)->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->isForm()
        && $r['to'] === '251911223344'
        && $r['message'] === 'Your code is 123456');
});

test('posts JSON', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    sendWith(['method' => 'json', 'recipient_field' => 'phone', 'message_field' => 'text']);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->isJson()
        && $r['phone'] === '251911223344'
        && $r['text'] === 'Your code is 123456');
});

test('sends a GET with the fields in the query string', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['method' => 'get']);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_contains($r->url(), 'to=251911223344'));
});

// ── Authentication ──

test('puts the key in a named header', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['auth_type' => 'header', 'auth_header' => 'KEY', 'auth_secret' => 'secret-key-1']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('KEY', 'secret-key-1'));
});

test('sends a bearer token', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['auth_type' => 'bearer', 'auth_secret' => 'tok-12345']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok-12345'));
});

test('puts username and password in the named fields', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith([
        'auth_type' => 'fields',
        'auth_username' => 'ethr',
        'auth_secret' => 'pass-word',
        'username_field' => 'user',
        'password_field' => 'pwd',
    ]);

    Http::assertSent(fn (Request $r) => $r['user'] === 'ethr' && $r['pwd'] === 'pass-word');
});

test('uses HTTP basic authentication', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['auth_type' => 'basic', 'auth_username' => 'ethr', 'auth_secret' => 'pass-word']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ethr:pass-word')));
});

test('sends no credential when authentication is none', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['auth_type' => 'none']);

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
});

test('adds the sender ID under its field', function () {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['sender_field' => 'from', 'sender_id' => 'ETHR']);

    Http::assertSent(fn (Request $r) => $r['from'] === 'ETHR');
});

// ── Number formats, both networks ──

test('writes the number the way the gateway asks', function (string $format, string $input, string $expected) {
    Http::fake(['*' => Http::response('OK')]);

    sendWith(['number_format' => $format], $input);

    Http::assertSent(fn (Request $r) => $r['to'] === $expected);
})->with([
    'Ethio Telecom, +2519…' => ['e164', '0911223344', '+251911223344'],
    'Ethio Telecom, 2519…' => ['international', '+251911223344', '251911223344'],
    'Ethio Telecom, 09…' => ['local', '251911223344', '0911223344'],
    'Safaricom, +2517…' => ['e164', '0711223344', '+251711223344'],
    'Safaricom, 07…' => ['local', '+251711223344', '0711223344'],
]);

test('refuses a landline without calling the gateway', function () {
    Http::fake();

    $result = sendWith([], '0111223344');

    expect($result->ok)->toBeFalse();
    Http::assertNothingSent();
});

// ── Success rule ──

test('a 2xx answer counts as sent when no rule is set', function () {
    Http::fake(['*' => Http::response('accepted', 202)]);

    expect(sendWith([])->ok)->toBeTrue();
});

test('a non-2xx answer is a failure', function () {
    Http::fake(['*' => Http::response('Denied', 403)]);

    $result = sendWith([]);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403);
});

test('a 200 carrying an error body is a failure under a JSON rule', function () {
    Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'insufficient balance'])]);

    $result = sendWith(['success_json_path' => 'status', 'success_json_value' => 'success']);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(200);
});

test('the JSON rule passes when the value matches, nested paths included', function () {
    Http::fake(['*' => Http::response(['data' => ['accepted' => true]])]);

    expect(sendWith(['success_json_path' => 'data.accepted', 'success_json_value' => 'true'])->ok)->toBeTrue();
});

test('a 200 without the expected text is a failure under a body rule', function () {
    Http::fake(['*' => Http::sequence()
        ->push('ERROR: invalid sender')
        ->push('SUCCESS id=42')]);

    expect(sendWith(['success_body_contains' => 'SUCCESS'])->ok)->toBeFalse()
        ->and(sendWith(['success_body_contains' => 'SUCCESS'])->ok)->toBeTrue();
});

test('a gateway that does not answer is a failure, not an exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $result = sendWith([]);

    expect($result->ok)->toBeFalse()->and($result->status)->toBeNull();
});

test('the credential is redacted from the response it reports', function () {
    Http::fake(['*' => Http::response('bad key: secret-key-1 for user ethr-user')]);

    $result = sendWith([
        'auth_type' => 'basic',
        'auth_username' => 'ethr-user',
        'auth_secret' => 'secret-key-1',
        'success_body_contains' => 'OK',
    ]);

    expect($result->response)
        ->not->toContain('secret-key-1')
        ->not->toContain('ethr-user')
        ->toContain('[redacted]');
});
