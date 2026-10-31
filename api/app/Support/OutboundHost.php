<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

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
 * This checks the name when it is saved and again before each request. A caller
 * that connects should take publicAddresses() and pin them into the connection
 * (CURLOPT_RESOLVE), as webhook delivery does; checking the name and letting
 * the client resolve it again leaves a gap when its DNS answer changes between
 * the two.
 */
final class OutboundHost
{
    /**
     * Not routable on the internet, and not refused by PHP's private and
     * reserved flags: carrier-grade NAT, IETF protocol assignments and the
     * benchmark range. 100.64.0.1 was accepted as external until 2026-10-14
     * (API/webhook end-to-end test, D8).
     */
    private const NOT_GLOBAL = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'];

    /** @var (Closure(string): list<string>)|null */
    private static ?Closure $resolver = null;

    public static function isInternal(string $host): bool
    {
        return self::publicAddresses($host) === null;
    }

    /**
     * The addresses a connection to this host may use, from one lookup: null
     * when the host is internal, the address itself for an IP literal, and an
     * empty list for a name that resolves to nothing.
     *
     * @return list<string>|null
     */
    public static function publicAddresses(string $host): ?array
    {
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B."));

        if ($host === '' || in_array($host, ['localhost', '0.0.0.0', '::', '::1'], true)) {
            return null;
        }

        foreach (['.localhost', '.local', '.internal', '.localdomain'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return null;
            }
        }

        if (in_array($host, ['metadata.google.internal', 'metadata.goog'], true)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPrivateIp($host) ? null : [$host];
        }

        // Any other all-numeric name is an IPv4 address in disguise, which
        // curl hands to inet_aton: 2130706433, 0x7f000001, 127.1 and the octal
        // 0177.0.0.1 all reach 127.0.0.1. PHP's resolver does not always
        // expand the short and octal forms (on Windows it returns nothing),
        // so they passed as external while curl connected them to loopback
        // (API/webhook end-to-end test, 2026-10-14, D8). No device or webhook
        // has a reason to be addressed that way.
        if (preg_match('/^((0x[0-9a-f]*|\d+)\.){0,3}(0x[0-9a-f]*|\d+)$/', $host) === 1) {
            return null;
        }

        $ips = self::resolve($host);
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return null;
            }
        }

        return $ips;
    }

    /**
     * Replaces DNS for tests, which cannot rely on a real answer. Null
     * restores it.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Anything but a globally routable address: private and reserved ranges,
     * plus the three NO_PRIV_RANGE | NO_RES_RANGE let through (NOT_GLOBAL).
     */
    public static function isPrivateIp(string $ip): bool
    {
        $embedded = self::embeddedIpv4($ip);
        if ($embedded !== null) {
            $ip = $embedded;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || IpUtils::checkIp($ip, self::NOT_GLOBAL);
    }

    /**
     * The IPv4 address an IPv6 one carries and reaches: IPv4-mapped
     * (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d), in either notation.
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (! str_contains($ip, ':')) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === "\0\x64\xff\x9b".str_repeat("\0", 8)) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return null;
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
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

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
