<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * What a phone or browser can tell about a punch beyond where it says it was:
 * the fix's altitude, speed, heading and age, which install of the app sent
 * it, and how far its clock is from the server's. The risk scoring reads
 * these (AttendanceRiskScorer): a fix that is minutes old, a phone that
 * reports none of the readings a real receiver does, or one install punching
 * for several people are the commonest ways attendance is cheated.
 *
 * The device id is a random value the app keeps on the install. It is stored
 * only as a keyed hash: enough to tell two punches came from one install,
 * not to recognise the device anywhere else.
 */
final class PunchSignals
{
    /** The request fields, all optional. Spread into a FormRequest's rules(). */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'altitude' => ['nullable', 'numeric', 'between:-500,9000'],
            $prefix.'speed' => ['nullable', 'numeric', 'min:0', 'max:500'],
            $prefix.'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            $prefix.'fix_age_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            $prefix.'device_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            $prefix.'client_time' => ['nullable', 'date'],
            $prefix.'webdriver' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The signals as punch metadata. `$online` says the punch was sent as it
     * happened, so the client's clock can be compared with the server's; an
     * offline punch is compared by its grant instead (OfflineGrant).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function from(array $input, ?string $userAgent = null, bool $online = true): array
    {
        $device = $input['device_id'] ?? null;
        $clientTime = $input['client_time'] ?? null;

        return array_filter([
            'altitude' => is_numeric($input['altitude'] ?? null) ? round((float) $input['altitude'], 1) : null,
            'speed' => is_numeric($input['speed'] ?? null) ? round((float) $input['speed'], 2) : null,
            'heading' => is_numeric($input['heading'] ?? null) ? round((float) $input['heading'], 1) : null,
            'fix_age_ms' => is_numeric($input['fix_age_ms'] ?? null) ? (int) $input['fix_age_ms'] : null,
            'device' => is_string($device) && $device !== '' ? self::deviceHash($device) : null,
            'client_offset_seconds' => $online && is_string($clientTime) && $clientTime !== ''
                ? (int) round(Carbon::parse($clientTime)->diffInSeconds(Carbon::now('UTC'), false))
                : null,
            'webdriver' => ($input['webdriver'] ?? false) === true ? true : null,
            'user_agent' => is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 200) : null,
        ], fn ($value) => $value !== null);
    }

    /** A keyed, truncated hash of an install's id: comparable, not reversible. */
    public static function deviceHash(string $deviceId): string
    {
        return substr(hash_hmac('sha256', $deviceId, (string) config('app.key')), 0, 32);
    }
}
