<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;

final class DeviceManager
{
    /**
     * The adapters whose terminals are found by a webhook token. ZKTeco ADMS
     * is found by its serial and the IP allowlist, and the mock and generic
     * adapters carry no terminal address, so the token means nothing to them.
     *
     * @var list<string>
     */
    public const WEBHOOK_TOKEN_ADAPTERS = ['hikvision', 'zkteco', 'suprema'];

    /** @var array<string, class-string<DeviceAdapter>> */
    private array $adapters = [
        'hikvision' => HikvisionAdapter::class,
        'zkteco' => ZktecoAdapter::class,
        'zkteco_adms' => ZktecoAdmsAdapter::class,
        'suprema' => SupremaAdapter::class,
        'generic' => GenericHttpAdapter::class,
        'mock' => MockAdapter::class,
    ];

    public function adapter(Device $device): DeviceAdapter
    {
        $type = $device->adapter_type;

        if (! isset($this->adapters[$type])) {
            throw new \InvalidArgumentException("Unknown device adapter type: {$type}");
        }

        // A Hikvision terminal with no address is one that sends to ETHR.
        // It keeps the `hikvision` type, so its token finds it on the same
        // push URL as a terminal ETHR can also call. An ADMS terminal keeps
        // its own adapter, which carries its commands and its roster.
        if (self::sendsToEthrOnlyHikvision($device)) {
            return app(HikvisionPushAdapter::class);
        }

        return app($this->adapters[$type]);
    }

    /**
     * Whether the device only sends to ETHR, and is therefore never pulled:
     * a Hikvision terminal registered without an address, every ZKTeco ADMS
     * terminal (it calls ETHR, ETHR never calls it), and a ZKTeco or Suprema
     * terminal with no address configured. Read from the stored config, so it
     * needs no column. Its status follows PushDeviceStatus.
     */
    public static function receivesOnly(Device $device): bool
    {
        if ($device->adapter_type === AdmsDeviceResolver::ADAPTER) {
            return true;
        }

        if (! in_array($device->adapter_type, ['hikvision', 'zkteco', 'suprema'], true)) {
            return false;
        }

        return ! self::hasAddress($device);
    }

    /**
     * Whether a webhook token is part of how this device connects, so a page
     * may show its address or offer a new one. Display only: every device is
     * still issued a token when it is registered.
     */
    public static function usesWebhookToken(Device $device): bool
    {
        return in_array($device->adapter_type, self::WEBHOOK_TOKEN_ADAPTERS, true);
    }

    /**
     * How the terminal and ETHR reach each other, for the page that sets it
     * up: `adms` (the terminal calls ETHR, found by serial), `push` (the
     * terminal calls ETHR with its token) or `pull` (ETHR calls the terminal).
     *
     * @return 'adms'|'push'|'pull'
     */
    public static function connectionMode(Device $device): string
    {
        if ($device->adapter_type === AdmsDeviceResolver::ADAPTER) {
            return 'adms';
        }

        return self::receivesOnly($device) ? 'push' : 'pull';
    }

    /**
     * Whether ETHR has no way to read this device's roster by asking it.
     * True for a terminal that only sends, except ADMS, whose roster is what
     * the terminal last reported (AdmsRoster).
     */
    public static function hasNoRoster(Device $device): bool
    {
        return $device->adapter_type !== AdmsDeviceResolver::ADAPTER && self::receivesOnly($device);
    }

    private static function sendsToEthrOnlyHikvision(Device $device): bool
    {
        return $device->adapter_type === 'hikvision' && ! self::hasAddress($device);
    }

    private static function hasAddress(Device $device): bool
    {
        $config = $device->getAttribute('connection_config');
        $ip = is_array($config) ? ($config['ip'] ?? null) : null;

        return is_string($ip) && trim($ip) !== '';
    }
}
