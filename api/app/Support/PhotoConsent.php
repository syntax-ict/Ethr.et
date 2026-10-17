<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * An employee's consent to the attendance selfie.
 *
 * A selfie is a facial image, and the Personal Data Protection Proclamation
 * No. 1321/2024 counts facial images as biometric (Art. 2(6)) and biometric
 * data as sensitive (Art. 2(5)). Sensitive data is processed on a lawful
 * basis, and written consent specific to the purpose, given before
 * processing, is the one ETHR can collect for every organisation. So the
 * phone asks before it opens the camera, the server refuses a selfie from
 * someone who has not agreed, and agreeing or withdrawing is the employee's
 * own act.
 *
 * Kept on `users.preferences` under its own key, which the preferences
 * endpoint neither reads nor writes. Every grant and withdrawal is also an
 * audit-log entry (immutable), which is the record that outlives a change of
 * mind. VERSION names the notice the employee agreed to: a materially changed
 * notice gets a new version, and consent to an older one no longer counts.
 * `2026-10-17.2`: the retention policy narrowed who sees a selfie (HR and the
 * department manager) and limited how long it is kept, so the notice changed.
 */
final class PhotoConsent
{
    public const VERSION = '2026-10-17.2';

    private const KEY = 'attendance_photo_consent';

    public static function given(?User $user): bool
    {
        return self::state($user)['given'];
    }

    /**
     * @return array{given: bool, version: string|null, given_at: string|null, current_version: string}
     */
    public static function state(?User $user): array
    {
        $stored = $user !== null && is_array($user->preferences) ? ($user->preferences[self::KEY] ?? null) : null;
        $version = is_array($stored) && is_string($stored['version'] ?? null) ? $stored['version'] : null;
        $at = is_array($stored) && is_string($stored['given_at'] ?? null) ? $stored['given_at'] : null;

        return [
            'given' => $version === self::VERSION && $at !== null,
            'version' => $version,
            'given_at' => $at,
            'current_version' => self::VERSION,
        ];
    }

    public static function give(User $user): void
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $preferences[self::KEY] = ['version' => self::VERSION, 'given_at' => Carbon::now()->toIso8601String()];
        $user->forceFill(['preferences' => $preferences])->save();

        AuditLog::record('profile.photo_consent_given', $user, ['version' => self::VERSION]);
    }

    public static function withdraw(User $user): void
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        if (! array_key_exists(self::KEY, $preferences)) {
            return;
        }
        unset($preferences[self::KEY]);
        $user->forceFill(['preferences' => $preferences])->save();

        AuditLog::record('profile.photo_consent_withdrawn', $user, ['version' => self::VERSION]);
    }
}
