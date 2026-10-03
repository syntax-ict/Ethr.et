<?php

declare(strict_types=1);

namespace App\Services\Device\Concerns;

use App\Exceptions\DeviceRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * The one rule every adapter's pullEvents() follows: a read that failed must
 * not come back as [], because [] is what an idle device returns and the sync
 * moves its cursor past it. Unreachable, non-2xx and not-JSON all throw.
 *
 * A 2xx JSON reply with no events in it is still an empty read — Hikvision
 * leaves `InfoList` out when nothing matched — so what the adapter does with a
 * missing events key is unchanged.
 */
trait ReadsDeviceEvents
{
    /**
     * @param  callable(): Response  $send
     *
     * @throws DeviceRequestFailed
     */
    private function readEvents(string $vendor, callable $send): Response
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
