<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Device;
use RuntimeException;

/**
 * The people enrolled on a device cannot be listed, because ETHR cannot call
 * the device: it is a terminal that only sends to ETHR (a Hikvision terminal
 * registered without an address, see DeviceManager::receivesOnly()).
 *
 * Its adapter answers an empty roster, which is also what a terminal with
 * nobody enrolled answers, so staging a batch from it produced an empty batch
 * that read as "nobody is enrolled". Throwing says the truer thing. This is not
 * DeviceRequestFailed: that is a read that was attempted and failed (502, retry
 * when the device is back); this is a read that cannot be attempted at all, so
 * bootstrap/app.php renders it as a 409 and retrying does not help.
 */
class DeviceRosterUnavailable extends RuntimeException
{
    public static function receivesOnly(Device $device): self
    {
        return new self("Device {$device->public_id} only sends to ETHR, so its roster cannot be read");
    }
}
