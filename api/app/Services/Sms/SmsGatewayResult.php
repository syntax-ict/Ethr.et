<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * What a gateway said: whether it counts as sent under its success rule, the
 * HTTP status (null when the request never got an answer), and the body with
 * every configured secret replaced — safe to show an operator and to store.
 */
final class SmsGatewayResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly string $response,
    ) {}
}
