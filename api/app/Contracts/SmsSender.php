<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * PHASE_05 S26 describes this interface as "existing". It did not exist: no file
 * in the project referenced SMS at all, while the notification-preferences API and
 * the Settings UI both offered an `sms` channel. A user could switch it on and
 * nothing anywhere would ever read the toggle, let alone send a message.
 *
 * Implementations must be free of paid dependencies per the stack rules, so the
 * shipped drivers are a log writer for development and an EthioTelecom HTTP
 * gateway for production.
 */
interface SmsSender
{
    /**
     * @param  string  $to  Recipient MSISDN. Ethiopian numbers may arrive as
     *                      `+2519…` or `09…`; implementations normalize.
     * @return bool True when the gateway accepted the message.
     */
    public function send(string $to, string $message): bool;

    /**
     * Whether this driver can actually deliver in the current environment.
     *
     * Surfaced to the client so the preferences UI can present SMS as unavailable
     * instead of rendering a toggle that silently does nothing.
     */
    public function isAvailable(): bool;
}
