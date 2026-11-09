<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a browser's push subscription may point: the push services of the
 * browsers ETHR runs in, over HTTPS on the default port.
 *
 * A subscription's endpoint is sent by the browser, so it is whatever the
 * caller says. It was accepted as any `https://` URL, and every notification
 * to that user then made this server POST to it, following redirects: an
 * employee could point it at the Plesk panel on 127.0.0.1:8443 or anything
 * else behind the host, and read the answers back from which subscriptions
 * the push service "reported gone" (review 2026-11-09). Checked when a
 * subscription is saved and again before every send, so one saved before the
 * check is never used.
 */
final class PushEndpoint
{
    /** Hosts allowed exactly. */
    private const HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
    ];

    /** Hosts allowed under these domains: Apple's and Microsoft's push services. */
    private const SUFFIXES = [
        '.push.apple.com',
        '.notify.windows.com',
    ];

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        if (in_array($host, self::HOSTS, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }
}
