<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\CollectsDeviceCommands;
use App\Contracts\DeviceAdapter;
use App\Models\Device;

/**
 * A ZKTeco terminal speaking ADMS, the "cloud server" push protocol built into
 * its firmware: it calls ETHR (`/iclock/...`, AdmsController) to send punches
 * and collect commands, so it works from a customer's LAN to shared hosting,
 * where ETHR cannot call it.
 *
 * Nothing here talks to the device. It is online while it keeps checking in.
 */
final class ZktecoAdmsAdapter implements CollectsDeviceCommands, DeviceAdapter
{
    public function connect(Device $device): bool
    {
        return $this->recentlySeen($device);
    }

    public function getStatus(Device $device): array
    {
        return [
            'online' => $this->recentlySeen($device),
            'mode' => 'adms',
            'last_seen' => $device->last_sync_at?->toIso8601String(),
        ];
    }

    public function getDeviceInfo(Device $device): array
    {
        return [
            'serial_number' => $device->serial_number,
            'manufacturer' => 'ZKTeco',
            'protocol' => 'ADMS (push)',
        ];
    }

    /** Punches are pushed, never pulled. */
    public function pullEvents(Device $device, ?string $since = null): array
    {
        return [];
    }

    /**
     * What the device last reported (AdmsRoster). It is asked with a command
     * and answers at its next check-in, so this is the list as of then.
     */
    public function pullEnrollments(Device $device): array
    {
        return app(AdmsRoster::class)->enrollments($device);
    }

    /** The address is entered on the device itself (Comm → Cloud Server Setting). */
    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        return false;
    }

    /** Heard from inside the push window (PushDeviceStatus): ADMS polls every few seconds. */
    private function recentlySeen(Device $device): bool
    {
        return PushDeviceStatus::statusFor($device) === 'online';
    }
}
