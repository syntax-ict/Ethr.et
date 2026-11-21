<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;

/**
 * What the stored `status` of a terminal that only sends must read, for every
 * such terminal: a Hikvision one with no address, a ZKTeco ADMS one, and a
 * ZKTeco or Suprema one registered with no address (DeviceManager::receivesOnly).
 *
 * One rule, so the badge, the list filter and the health counts cannot
 * disagree: `pending` is never heard from (configured, not yet contacted),
 * `online` heard from in the last 15 minutes, `quiet` heard from in the last
 * 24 hours but not the last 15, `offline` older than that. `quiet` is its own
 * state because ETHR cannot call such a terminal: once the evidence is stale
 * it cannot tell a switched-off terminal from one nobody is using, and
 * "Online" would claim more than it knows.
 *
 * The pull job never touches such a device, so without this a first contact
 * left it Online for good, whatever happened to the terminal afterwards. An
 * ADMS terminal with automatic sync on was the case that kept it: its empty
 * pull moved `last_sync_at`, so it looked heard from forever (BASELINE 25z).
 */
final class PushDeviceStatus
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

    /** @return 'pending'|'online'|'quiet'|'offline' */
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
    public static function settle(Device $device): string
    {
        $status = self::statusFor($device);

        if ($device->status !== $status) {
            $device->update(['status' => $status]);
        }

        return $status;
    }
}
