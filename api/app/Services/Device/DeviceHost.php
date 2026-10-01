<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Support\OutboundHost;
use RuntimeException;

/**
 * The address a device adapter may connect to.
 *
 * A device's host is chosen by a tenant admin and the server connects to it
 * from `devices:sync`, the pull job and the status endpoint, whose response
 * carries the connection error. Unchecked, that is a request forgery: a tenant
 * can make the server probe its own loopback and private network and read which
 * ports answer.
 *
 * Biometric devices normally do live on a private LAN, so whether a private
 * address is allowed is a deployment decision, not a validation rule:
 * `devices.allow_private_hosts`. On multi-tenant shared hosting it is off, and
 * the server could not reach a customer's LAN there anyway. On an on-premise
 * install, where the server and the devices share a network, turn it on.
 */
final class DeviceHost
{
    public static function privateHostsAllowed(): bool
    {
        return (bool) config('devices.allow_private_hosts', false);
    }

    /** The reason this host is refused, or null when it may be used. */
    public static function refusal(string $host): ?string
    {
        if (! OutboundHost::isWellFormed($host)) {
            return __('device.host_malformed');
        }

        if (! self::privateHostsAllowed() && OutboundHost::isInternal($host)) {
            return __('device.host_internal');
        }

        return null;
    }

    /** Checked again before every request: stored rows predate the rule, and DNS can change. */
    public static function assertAllowed(string $host): void
    {
        $refusal = self::refusal($host);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }
    }

    /**
     * `scheme://host:port` for the vendor adapters that address a device by its
     * `ip` and `port` config keys (Hikvision, ZKTeco, Suprema).
     *
     * This only formats. It does not check the host: each adapter calls
     * assertAllowed() on the line before, and must keep doing so. Validating
     * here as well would resolve the host twice per request. The generic
     * adapter does not use this. It is configured with a whole `base_url` and
     * joins request paths onto it, which needs a different check, the
     * same-host check in GenericHttpAdapter::path().
     *
     * An IPv6 literal is bracketed (RFC 3986 §3.2.2). refusal() accepts a
     * bare IPv6 address, and written unbracketed, `http://2606:4700::1:80/`,
     * curl rejects the URL before connecting.
     */
    public static function baseUrl(string $scheme, string $host, string $port): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = "[{$host}]";
        }

        return "{$scheme}://{$host}:{$port}";
    }
}
