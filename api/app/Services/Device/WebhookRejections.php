<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;

/**
 * Why a terminal's calls are being refused, kept where its admin can see it.
 *
 * A refused webhook used to leave nothing but a log line, so a terminal set up
 * with a stale or cut-off address looked exactly like one that never called:
 * "Nothing received from this terminal yet". This keeps the last refusal for
 * a week, on the device it can honestly be traced to, and only then.
 *
 * What can be traced, and how:
 *  - token_retired: the terminal sent a token this device had before it was
 *    regenerated. Rotation remembers a hash of the old token, never the token.
 *  - token_truncated: the terminal sent the start of this device's token and
 *    no more, as a terminal whose address field is too short does. Attempted
 *    only for a hex string of at least MIN_PREFIX characters, so a wildcard
 *    cannot be smuggled into the prefix match, and only when one device of
 *    that vendor matches.
 *  - token_missing: the request named this device by its serial but carried
 *    no token, and the device has one.
 *  - ip_not_allowed: a token-less device called from an address not on its
 *    allowlist.
 *
 * What cannot: a token mistyped from the start. Nothing else in a Hikvision
 * push names the device (the serial is not in it), and the source address
 * cannot stand in: terminals of two organisations may share one public
 * address behind carrier NAT, and showing one organisation the other's
 * terminal would be worse than showing nothing.
 */
final class WebhookRejections
{
    public const TTL_DAYS = 7;

    /** The shortest token prefix ETHR will trace; 16 hex characters is 64 bits. */
    public const MIN_PREFIX = 16;

    /** A terminal retries every few seconds; one write a minute is enough to say so. */
    private const REWRITE_AFTER_SECONDS = 60;

    /**
     * Remember a token a device no longer accepts, so a terminal still sending
     * it can be named. Called when the token is regenerated.
     */
    public static function retire(Device $device, ?string $oldToken): void
    {
        if ($oldToken === null || $oldToken === '') {
            return;
        }

        try {
            Cache::put(self::retiredKey($oldToken), $device->id, now()->addDays(self::TTL_DAYS));
        } catch (\Throwable) {
            // The rotation itself must not fail because the trace could not be kept.
        }
    }

    /**
     * Trace a call refused for its token to the device it was meant for, when
     * that can be done without guessing.
     */
    public static function traceToken(string $adapterType, string $token, ?string $ip): void
    {
        try {
            $device = self::retiredFor($adapterType, $token);
            $reason = 'token_retired';

            if ($device === null) {
                $device = self::truncatedFor($adapterType, $token);
                $reason = 'token_truncated';
            }

            if ($device !== null) {
                self::record($device, $reason, $ip);
            }
        } catch (\Throwable) {
            // A refusal is the answer either way; the trace is a courtesy.
        }
    }

    /** A refusal of a device already in hand: its token missing, or its address not allowed. */
    public static function record(Device $device, string $reason, ?string $ip): void
    {
        try {
            $key = self::rejectionKey($device);
            $last = Cache::get($key);

            if (is_array($last) && ($last['reason'] ?? null) === $reason && isset($last['at'])
                && now()->diffInSeconds($last['at'], true) < self::REWRITE_AFTER_SECONDS) {
                return;
            }

            Cache::put($key, ['reason' => $reason, 'ip' => $ip, 'at' => now()->toIso8601String()], now()->addDays(self::TTL_DAYS));
        } catch (\Throwable) {
            // A downed cache must not turn a 401 into a 500.
        }
    }

    /**
     * The last refusal, unless the terminal has been heard from since: a fixed
     * terminal does not keep saying it was refused.
     *
     * @return array{reason: string, ip: string|null, at: string}|null
     */
    public static function last(Device $device): ?array
    {
        try {
            $value = Cache::get(self::rejectionKey($device));
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($value) || ! isset($value['at'], $value['reason'])) {
            return null;
        }

        if ($device->last_sync_at !== null && $device->last_sync_at->greaterThanOrEqualTo($value['at'])) {
            return null;
        }

        return ['reason' => (string) $value['reason'], 'ip' => $value['ip'] ?? null, 'at' => (string) $value['at']];
    }

    private static function retiredFor(string $adapterType, string $token): ?Device
    {
        $id = Cache::get(self::retiredKey($token));
        if (! is_int($id)) {
            return null;
        }

        // Keyed on a device id the rotation itself stored, from a token that
        // device was issued: the tenant is what the lookup finds out.
        return Device::withoutGlobalScope('tenant')
            ->whereKey($id)
            ->where('adapter_type', $adapterType)
            ->first();
    }

    private static function truncatedFor(string $adapterType, string $token): ?Device
    {
        $length = strlen($token);
        if ($length < self::MIN_PREFIX || $length >= 64 || preg_match('/^[0-9a-f]+$/', $token) !== 1) {
            return null;
        }

        // Hex only, checked above, so the prefix carries no LIKE wildcard.
        $candidates = Device::withoutGlobalScope('tenant')
            ->where('adapter_type', $adapterType)
            ->where('webhook_token', 'like', $token.'%')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private static function retiredKey(string $token): string
    {
        return 'device-webhook:retired:'.hash('sha256', $token);
    }

    private static function rejectionKey(Device $device): string
    {
        return 'device-webhook:rejected:'.$device->id;
    }
}
