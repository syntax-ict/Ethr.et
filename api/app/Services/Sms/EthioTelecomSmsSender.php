<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EthioTelecom bulk-SMS HTTP gateway.
 *
 * Reports itself unavailable until endpoint and credentials are configured, so an
 * unconfigured deployment presents SMS as off rather than accepting opt-ins it
 * cannot honour.
 *
 * The request shape follows the operator's standard bulk-SMS form post. It has
 * not been exercised against the live gateway in this environment — the driver
 * defaults to `log` and this class is selected only by explicit `SMS_DRIVER`
 * configuration, so an unverified integration cannot be reached by accident.
 */
class EthioTelecomSmsSender implements SmsSender
{
    public function isAvailable(): bool
    {
        $config = config('sms.ethiotelecom');

        return ! empty($config['endpoint'])
            && ! empty($config['username'])
            && ! empty($config['password']);
    }

    public function send(string $to, string $message): bool
    {
        if (! $this->isAvailable()) {
            Log::warning('SMS not sent: EthioTelecom gateway is not configured.');

            return false;
        }

        $number = SmsNumber::normalize($to);

        if (! SmsNumber::isValid($number)) {
            Log::warning('SMS not sent: recipient is not a valid Ethiopian mobile number.');

            return false;
        }

        $config = config('sms.ethiotelecom');

        try {
            $response = Http::timeout((int) $config['timeout'])
                ->asForm()
                ->post($config['endpoint'], [
                    'username' => $config['username'],
                    'password' => $config['password'],
                    'from' => $config['sender_id'],
                    'to' => $number,
                    'text' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            // The number itself is not logged — it is personal data, and the
            // gateway's own logs already hold it.
            Log::warning('SMS gateway rejected the message', [
                'status' => $response->status(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('SMS gateway request failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
