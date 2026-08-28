<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development driver. Writes the message where a developer can read it and
 * reports itself **unavailable** — the preferences UI keys the SMS toggle off
 * `isAvailable()`, and a log line is not delivery. Claiming otherwise is exactly
 * the failure mode this whole slice exists to remove.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        Log::channel(config('logging.default'))->info('SMS (log driver)', [
            'to' => SmsNumber::normalize($to),
            'message' => $message,
        ]);

        return true;
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
