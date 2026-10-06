<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A device adapter could not read events or enrollments: the device or its
 * middleware was unreachable, answered non-2xx, or answered with something that
 * is not JSON.
 *
 * The adapters used to return [] instead, which is also what an idle device
 * returns. PullDeviceEventsJob cannot tell the two apart, so a failed read
 * looked like "nothing new" and an incremental sync moved last_sync_at past
 * punches it never read. Throwing sends the job down the path it already has
 * for this: device marked `error`, a failed sync log, one retry, then
 * DeviceSyncFailed to the tenant's admins, with the cursor where it was.
 *
 * Over HTTP — enrollment discovery, staging a migration from a device —
 * bootstrap/app.php renders it as a 502 with a translated message: the fault is
 * upstream of this server, and the caller can retry once the device is back.
 *
 * The message names the vendor and what went wrong, never the address or the
 * credentials in the connection config: it lands in the sync log and in the
 * admin notification.
 */
class DeviceRequestFailed extends RuntimeException
{
    /**
     * The transport error is chained, not quoted: a cURL message carries the
     * full URL, and a generic adapter's `base_url` may embed `user:pass@`.
     */
    public static function unreachable(string $vendor, Throwable $previous): self
    {
        return new self("{$vendor} device unreachable: the connection failed or timed out", 0, $previous);
    }

    public static function status(string $vendor, Response $response): self
    {
        return new self("{$vendor} device answered HTTP {$response->status()}");
    }

    public static function unreadable(string $vendor): self
    {
        return new self("{$vendor} device answered with a body that is not JSON");
    }
}
