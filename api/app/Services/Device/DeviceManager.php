<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;

final class DeviceManager
{
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
        // push URL as a terminal ETHR can also call.
        if (self::receivesOnly($device)) {
            return app(HikvisionPushAdapter::class);
        }

        return app($this->adapters[$type]);
    }

    /**
     * Whether the device only sends to ETHR: a Hikvision terminal registered
     * without an address. Read from the stored config, so it needs no column.
     */
    public static function receivesOnly(Device $device): bool
    {
        if ($device->adapter_type !== 'hikvision') {
            return false;
        }

        $config = $device->getAttribute('connection_config');
        $ip = is_array($config) ? ($config['ip'] ?? null) : null;

        return ! is_string($ip) || trim($ip) === '';
    }
}
