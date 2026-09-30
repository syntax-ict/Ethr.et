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
}
