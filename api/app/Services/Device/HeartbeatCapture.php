<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\DeviceInboundPunch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The last raw bodies a Hikvision terminal sent that were not an access
 * event, so a fixture can be written from what a terminal really sends
 * instead of from the documentation (BASELINE 25z, F12).
 *
 * Twenty per device, kept in the cache for 24 hours, never in a table. They
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
            $kept = Cache::get($key);
            $kept = is_array($kept) ? $kept : [];

            $kept[] = [
                'at' => now()->toIso8601String(),
                'body' => DeviceInboundPunch::capRaw($request->input()),
            ];

            Cache::put($key, array_slice($kept, -self::KEEP), now()->addHours(self::TTL_HOURS));
        } catch (\Throwable) {
            // A downed cache must not turn an acknowledged post into a 500.
        }
    }

    /** @return list<array{at: string, body: string|null}> */
    public static function all(Device $device): array
    {
        try {
            $kept = Cache::get(self::key($device->id));
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
