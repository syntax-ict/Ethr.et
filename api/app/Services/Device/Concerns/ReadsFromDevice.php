<?php

declare(strict_types=1);

namespace App\Services\Device\Concerns;

use App\Exceptions\DeviceRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * The one rule every adapter's pullEvents() and pullEnrollments() follow: a
 * read that failed must not come back as [], because [] is also what an idle
 * device, or one with nobody enrolled, returns. Unreachable, non-2xx and
 * not-JSON all throw.
 *
 * For events, [] made the sync move its cursor past punches it never read. For
 * enrollments it showed an empty roster, and staged an empty migration batch,
 * for a device that was simply down.
 *
 * A 2xx JSON reply with nothing in it is still an empty read — Hikvision leaves
 * `InfoList` out when nothing matched — so what the adapter does with a missing
 * key is unchanged.
 */
trait ReadsFromDevice
{
    /**
     * @param  callable(): Response  $send
     *
     * @throws DeviceRequestFailed
     */
    private function readDevice(string $vendor, callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw DeviceRequestFailed::unreachable($vendor, $e);
        }

        if (! $response->successful()) {
            throw DeviceRequestFailed::status($vendor, $response);
        }

        if (! is_array($response->json())) {
            throw DeviceRequestFailed::unreadable($vendor);
        }

        return $response;
    }
}
