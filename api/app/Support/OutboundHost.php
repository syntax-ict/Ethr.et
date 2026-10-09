<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a host the server is about to connect to on a tenant's behalf points
 * back inside the server's own network.
 *
 * Shared by webhook URLs (ExternalUrl) and device addresses (DeviceHost), which
 * are the two places a tenant chooses a host the server then connects to.
 *
 * Two bypasses the previous inline check in ExternalUrl allowed, and this does not:
 * - parse_url() returns IPv6 hosts in brackets (`[::1]`), which is not an IP to
 *   filter_var() and resolves to nothing, so `http://[::1]/` passed as external.
 * - An IPv4-mapped IPv6 address (`::ffff:127.0.0.1`) reaches the IPv4 host it
 *   embeds, so it is judged by that address.
 *
 * This checks the name when it is saved and again before each request. It does
 * not pin the resolved address into the connection, so a hostname whose DNS
 * changes between the check and the connect (rebinding) is a residual gap.
 */
final class OutboundHost
{
    public static function isInternal(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B."));

        if ($host === '' || in_array($host, ['localhost', '0.0.0.0', '::', '::1'], true)) {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.localdomain'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (in_array($host, ['metadata.google.internal', 'metadata.goog'], true)) {
            return true;
        }

        // A bare integer or hex literal is an IPv4 address in disguise
        // (2130706433 and 0x7f000001 are both 127.0.0.1). No device or webhook
        // has a reason to be addressed that way.
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/', $host) === 1) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPrivateIp($host);
        }

        foreach (self::resolve($host) as $ip) {
            if (self::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivateIp(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** A bare IP address or DNS hostname: no scheme, port, path, userinfo or brackets. */
    public static function isWellFormed(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
