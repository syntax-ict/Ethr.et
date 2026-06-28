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
    ];

    public function adapter(Device $device): DeviceAdapter
    {
        $type = $device->adapter_type;

        if (! isset($this->adapters[$type])) {
            throw new \InvalidArgumentException("Unknown device adapter type: {$type}");
        }

        return app($this->adapters[$type]);
    }

    public function supportedTypes(): array
    {
        return array_keys($this->adapters);
    }
}
