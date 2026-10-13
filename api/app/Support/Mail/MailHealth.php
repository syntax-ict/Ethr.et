<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Cache;

/**
 * When mail last left, when it last failed, and how the TLS connection was
 * trusted — so "is email working?" has an answer on /health instead of in a
 * log file on a host with no shell. Written by ResilientSmtpTransport.
 *
 * Timestamps and a mode only: an SMTP error message can carry a host name or
 * an address, and /health is public.
 */
final class MailHealth
{
    private const SENT = 'ethr:mail:last_sent_at';

    private const FAILED = 'ethr:mail:last_failed_at';

    private const TLS = 'ethr:mail:tls';

    private const TTL = 2592000;

    public static function recordSent(string $tls): void
    {
        self::write([self::SENT => now()->toIso8601String(), self::TLS => $tls]);
    }

    public static function recordFailure(): void
    {
        self::write([self::FAILED => now()->toIso8601String()]);
    }

    /**
     * A broken cache must never stop an email: the record is a convenience,
     * the email is the point.
     *
     * @param  array<string, string>  $values
     */
    private static function write(array $values): void
    {
        try {
            Cache::putMany($values, self::TTL);
        } catch (\Throwable) {
            // Reported by /health's own cache check.
        }
    }

    /**
     * Nulls when the cache cannot be read: /health already reports a broken
     * cache store, and must not fail on the way to saying so.
     *
     * @return array{last_sent_at: string|null, last_failed_at: string|null, tls: string|null}
     */
    public static function snapshot(): array
    {
        $read = static function (string $key): ?string {
            try {
                $value = Cache::get($key);
            } catch (\Throwable) {
                return null;
            }

            return is_string($value) ? $value : null;
        };

        return [
            'last_sent_at' => $read(self::SENT),
            'last_failed_at' => $read(self::FAILED),
            'tls' => $read(self::TLS),
        ];
    }
}
