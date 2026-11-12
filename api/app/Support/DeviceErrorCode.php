<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A stable code for why ETHR could not reach a device, read from the adapter's
 * own message. The code is what the API publishes next to the raw message; the
 * advice for it is written in the dictionaries (`device_error.hint.<code>`), so
 * a reader gets it in their own language and PHP carries no English hint.
 *
 *   sdk_port   refused or timed out on port 8000, Hikvision's SDK port; ETHR
 *              speaks ISAPI over HTTP, usually port 80
 *   refused    cURL 7, nothing answered at that address and port
 *   timeout    cURL 28
 *   dns        cURL 6, the name did not resolve
 *   auth       HTTP 401 or 403
 *   http       any other HTTP status
 *   unreachable  the message older jobs wrote, which carried no cause
 *   other      anything else; the raw message is shown on its own
 *
 * A 401 deliberately has no "check the password" advice: ETHR sends Basic
 * authentication only and Hikvision ISAPI commonly requires Digest, so a
 * correct password can still be refused (docs/DEVICE_INTEGRATION.md).
 */
final class DeviceErrorCode
{
    public const SDK_PORT = 'sdk_port';

    public const REFUSED = 'refused';

    public const TIMEOUT = 'timeout';

    public const DNS = 'dns';

    public const AUTH = 'auth';

    public const HTTP = 'http';

    public const UNREACHABLE = 'unreachable';

    public const OTHER = 'other';

    /** Hikvision's SDK port, which is not the HTTP port ISAPI answers on. */
    private const SDK_PORT_NUMBER = '8000';

    /**
     * The adapter's message as it may be stored and shown to HR. A transport
     * error can quote the URL it called, with the credentials a generic
     * device's address carries and any query string, so both are dropped (the
     * adapters' own exceptions refuse to quote it for the same reason).
     */
    public static function scrub(string $message, int $limit = 500): string
    {
        $message = (string) preg_replace('#(?<=://)[^/@\s]+@#', '', $message);
        $message = (string) preg_replace('/\?\S*/', '', $message);

        return mb_substr(trim($message), 0, $limit);
    }

    public static function from(?string $message): ?string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return null;
        }

        if ($message === 'Device is not reachable') {
            return self::UNREACHABLE;
        }

        if (preg_match('/cURL error (\d+)/i', $message, $curl) === 1) {
            $code = match ((int) $curl[1]) {
                7 => self::REFUSED,
                28 => self::TIMEOUT,
                6 => self::DNS,
                default => self::OTHER,
            };

            $onSdkPort = preg_match('/\bport (\d+)\b/i', $message, $port) === 1
                && $port[1] === self::SDK_PORT_NUMBER;

            return $onSdkPort && in_array($code, [self::REFUSED, self::TIMEOUT], true)
                ? self::SDK_PORT
                : $code;
        }

        if (preg_match('/^HTTP (\d{3})\b/', $message, $http) === 1) {
            return in_array((int) $http[1], [401, 403], true) ? self::AUTH : self::HTTP;
        }

        return self::OTHER;
    }
}
