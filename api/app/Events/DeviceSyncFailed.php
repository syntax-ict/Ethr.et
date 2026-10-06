<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A device's sync machinery threw and exhausted its retries.
 *
 * DELIBERATELY NOT `DeviceOffline`, and that is the whole point of this class.
 * `offline` means the adapter's own reachability check reported the device
 * down; `error` means our sync threw — a bad credential, a schema the adapter
 * cannot parse, a timeout mid-pull. The `Device` model already distinguishes
 * the two statuses, and an admin needs to know which one they have: a device
 * that is off is someone else's problem to power on, and a device that is
 * erroring is ours.
 *
 * `docs/CLAUDE.md`'s queue-recovery table recorded the gap this closes: the
 * table claimed the `devices` path triggers `DeviceOffline` and notifies an
 * admin, while `PullDeviceEventsJob` had no `failed()` at all. It dispatches
 * `DeviceOffline` only from the SUCCESS path, when the adapter says the device
 * is unreachable. So an unreachable device politely notified an admin and a
 * device whose sync threw twice notified nobody.
 *
 * NOT `ShouldBroadcast`, unlike `DeviceOffline`. Broadcasting is not deployed
 * on either target — `BROADCAST_CONNECTION` is `log` on the VPS and `null` on
 * shared hosting — so implementing it would add a live-update path that nothing
 * carries, on a transport this deployment has none of. The database and mail
 * channels on the notification are what actually reach anyone.
 */
class DeviceSyncFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Device $device,
        public readonly string $reason,
    ) {}
}
