<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Contracts\ReceivesPunchesOnly;
use App\Models\Device;

/**
 * A Hikvision terminal registered with no address: it sends each event to
 * ETHR (HTTP listening on the terminal, `POST /devices/webhook/hikvision`),
 * and ETHR never connects to it.
 *
 * A terminal normally sits on an office network, and on shared hosting ETHR
 * cannot reach one there: `DeviceHost` refuses a private address, and the
 * form required an address, so such a terminal could not be registered at
 * all (2026-11-11). The push handler never needed the address. It finds the
 * device by its token.
 *
 * What this mode does not do, because each needs a connection to the
 * terminal: read the enrolled users, pull missed events, set the clock, add
 * or remove a person. A punch made while the terminal could not reach ETHR is
 * resent only if the terminal's own firmware resends it.
 */
final class HikvisionPushAdapter implements DeviceAdapter, ReceivesPunchesOnly
{
    /**
     * A terminal that has sent nothing for this long is shown as offline. It
     * is a label, not a gate: a punch from an "offline" terminal is still
     * recorded. Long enough to cover a night; a weekend will show offline.
     */
    public const OFFLINE_AFTER_HOURS = 24;

    public function connect(Device $device): bool
    {
        return $this->recentlyHeardFrom($device);
    }

    public function getStatus(Device $device): array
    {
        return [
            'online' => $this->recentlyHeardFrom($device),
            'mode' => 'push',
            'last_seen' => $device->last_sync_at?->toIso8601String(),
        ];
    }

    public function getDeviceInfo(Device $device): array
    {
        return [
            'serial_number' => $device->serial_number,
            'manufacturer' => 'Hikvision',
            'protocol' => 'ISAPI event notification (push)',
        ];
    }

    /** Punches are pushed, never pulled. */
    public function pullEvents(Device $device, ?string $since = null): array
    {
        return [];
    }

    /** The roster is on the terminal, and nothing here can read it. */
    public function pullEnrollments(Device $device): array
    {
        return [];
    }

    /** The address is entered on the terminal itself (Network, HTTP listening). */
    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        return false;
    }

    private function recentlyHeardFrom(Device $device): bool
    {
        return $device->last_sync_at !== null
            && $device->last_sync_at->greaterThan(now()->subHours(self::OFFLINE_AFTER_HOURS));
    }
}
