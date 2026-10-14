<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\SmsGateway;
use App\Support\EthiopianPhone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Any HTTP SMS gateway, described by configuration rather than code: where to
 * send, how to authenticate, what the fields are called, how the number is
 * written, and what a successful answer looks like.
 *
 * There is deliberately no per-provider class. Ethio Telecom publishes no HTTP
 * SMS API (its partner page asks for SMPP or SS7), and an aggregator's API is
 * whatever its documentation says on subscription day — so the operator enters
 * that documentation into the console, and the test button proves it.
 *
 * Success is HTTP 2xx AND, when configured, a JSON value at a path or a
 * substring of the body: many gateways answer 200 to a rejected message, so the
 * status alone would report an undelivered code as sent.
 */
final class HttpSmsGateway
{
    /** Kept short: it is stored and shown in the console. */
    private const RESPONSE_LIMIT = 2000;

    public function __construct(private readonly SmsGateway $gateway) {}

    public function slot(): string
    {
        return $this->gateway->slot;
    }

    public function send(string $to, string $message): SmsGatewayResult
    {
        $number = $this->format($to);

        if ($number === null) {
            return new SmsGatewayResult(false, null, 'Not an Ethiopian mobile number.');
        }

        $fields = [
            $this->gateway->recipient_field => $number,
            $this->gateway->message_field => $message,
        ];

        if (filled($this->gateway->sender_field) && filled($this->gateway->sender_id)) {
            $fields[(string) $this->gateway->sender_field] = $this->gateway->sender_id;
        }

        if ($this->gateway->auth_type === 'fields') {
            if (filled($this->gateway->username_field)) {
                $fields[(string) $this->gateway->username_field] = (string) $this->gateway->auth_username;
            }
            if (filled($this->gateway->password_field)) {
                $fields[(string) $this->gateway->password_field] = (string) $this->gateway->auth_secret;
            }
        }

        try {
            $response = $this->dispatch($this->request(), $fields);
        } catch (Throwable $e) {
            return new SmsGatewayResult(false, null, $this->redact('Request failed: '.$e->getMessage()));
        }

        return new SmsGatewayResult(
            $this->succeeded($response),
            $response->status(),
            $this->redact($response->body()),
        );
    }

    /** The number as this gateway wants it, or null when it is not a mobile. */
    public function format(string $to): ?string
    {
        if (! EthiopianPhone::isMobile($to)) {
            return null;
        }

        $e164 = (string) EthiopianPhone::canonical($to);

        return match ($this->gateway->number_format) {
            'e164' => $e164,
            'local' => '0'.substr($e164, 4),
            default => substr($e164, 1),
        };
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(max(1, $this->gateway->timeout_seconds))
            ->acceptJson();

        $secret = (string) $this->gateway->auth_secret;

        return match ($this->gateway->auth_type) {
            'header' => $request->withHeaders([(string) $this->gateway->auth_header => $secret]),
            'bearer' => $request->withToken($secret),
            'basic' => $request->withBasicAuth((string) $this->gateway->auth_username, $secret),
            default => $request,
        };
    }

    /** @param  array<string, string>  $fields */
    private function dispatch(PendingRequest $request, array $fields): Response
    {
        $endpoint = $this->gateway->endpoint;

        return match ($this->gateway->method) {
            'json' => $request->asJson()->post($endpoint, $fields),
            'get' => $request->get($endpoint, $fields),
            default => $request->asForm()->post($endpoint, $fields),
        };
    }

    private function succeeded(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $path = $this->gateway->success_json_path;
        if (filled($path)) {
            $actual = data_get($response->json(), (string) $path);

            if (! is_scalar($actual) || $this->scalarText($actual) !== (string) $this->gateway->success_json_value) {
                return false;
            }
        }

        $needle = $this->gateway->success_body_contains;
        if (filled($needle) && ! str_contains($response->body(), (string) $needle)) {
            return false;
        }

        return true;
    }

    /** JSON `true` reads as "true", so an operator can type what the docs show. */
    private function scalarText(bool|float|int|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private function redact(string $text): string
    {
        $secrets = array_filter([
            (string) $this->gateway->auth_secret,
            (string) $this->gateway->auth_username,
        ], fn (string $s): bool => strlen($s) >= 4);

        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return Str::limit($text, self::RESPONSE_LIMIT);
    }
}
