<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Support\HealthSignals;
use Illuminate\Http\Request;

/**
 * The last raw bodies a Hikvision terminal sent that were not an access
 * event, so a fixture can be written from what a terminal really sends
 * instead of from the documentation (BASELINE 25ab, F12).
 *
 * Twenty per device, kept for 24 hours in the signals store (the one a release's
 * `optimize:clear` does not empty), never in a table. Only the body is kept: the
 * query string carries the device's token, and a stored token would outlive its
 * rotation. They
 * hold whatever the terminal put in the notification, so they are not shown
 * to an organisation: only the platform export reads them, and it redacts
 * them first (DeviceCaptureRedactor).
 */
final class HeartbeatCapture
{
    public const KEEP = 20;

    public const TTL_HOURS = 24;

    public static function note(Device $device, Request $request): void
    {
        try {
            $key = self::key($device->id);
            $kept = HealthSignals::store()->get($key);
            $kept = is_array($kept) ? $kept : [];

            $kept[] = [
                'at' => now()->toIso8601String(),
                'body' => DeviceInboundPunch::capRaw(self::body($request)),
            ];

            HealthSignals::store()->put($key, array_slice($kept, -self::KEEP), now()->addHours(self::TTL_HOURS));
        } catch (\Throwable) {
            // A downed cache must not turn an acknowledged post into a 500.
        }
    }

    /** What the terminal posted, without the query string where its token travels. */
    private static function body(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    /** @return list<array{at: string, body: string|null}> */
    public static function all(Device $device): array
    {
        try {
            $kept = HealthSignals::store()->get(self::key($device->id));
        } catch (\Throwable) {
            return [];
        }

        return is_array($kept) ? array_values($kept) : [];
    }

    private static function key(int $deviceId): string
    {
        return 'device-heartbeats:'.$deviceId;
    }
}
