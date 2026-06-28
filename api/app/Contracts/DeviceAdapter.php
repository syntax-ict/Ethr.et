<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Device;

interface DeviceAdapter
{
    public function connect(Device $device): bool;

    public function getStatus(Device $device): array;

    public function getDeviceInfo(Device $device): array;

    /**
     * @return array<int, array{employee_badge: string, timestamp: string, type: string}>
     */
    public function pullEvents(Device $device, ?string $since = null): array;

    public function pushEventUrl(Device $device, string $callbackUrl): bool;
}
