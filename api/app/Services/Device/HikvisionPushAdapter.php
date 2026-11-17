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
     * Heard from inside this window, a terminal is `online`: ETHR has fresh
     * evidence, which is all "online" can honestly mean for a terminal it
     * cannot call.
     */
    public const ONLINE_WITHIN_MINUTES = 15;

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
        return self::statusFor($device) === 'online';
    }

    /**
     * What the stored `status` of a terminal that only sends must read. One
     * rule, so the badge, the list filter and the health counts cannot
     * disagree: `pending` is never heard from (configured, not yet
     * contacted), `online` heard from in the last 15 minutes, `quiet` heard
     * from in the last 24 hours but not the last 15, `offline` older than
     * that. `quiet` is its own state because ETHR cannot call this terminal:
     * once the evidence is stale it cannot tell a switched-off terminal from
     * one nobody is using, and "Online" would claim more than it knows. The
     * pull job never touches such a device, so without this a first punch
     * left it Online for good, whatever happened to the terminal afterwards.
     *
     * @return 'pending'|'online'|'quiet'|'offline'
     */
    public static function statusFor(Device $device): string
    {
        if ($device->last_sync_at === null) {
            return 'pending';
        }

        if ($device->last_sync_at->greaterThan(now()->subMinutes(self::ONLINE_WITHIN_MINUTES))) {
            return 'online';
        }

        return $device->last_sync_at->greaterThan(now()->subHours(self::OFFLINE_AFTER_HOURS))
            ? 'quiet'
            : 'offline';
    }

    /**
     * Write the rule's answer onto the row, and only when it differs, so a
     * run every five minutes does not touch `updated_at` on every terminal.
     * Returns the status the device now has.
     */
    public static function settleStatus(Device $device): string
    {
        $status = self::statusFor($device);

        if ($device->status !== $status) {
            $device->update(['status' => $status]);
        }

        return $status;
    }
}
