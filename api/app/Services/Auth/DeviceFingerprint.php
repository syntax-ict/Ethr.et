<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Stable per-browser device identity, used by the active-sessions list and by
 * trusted-device MFA skipping (PHASE_00 S03).
 *
 * The identity is a 32-byte random opaque token held in a long-lived cookie. Only
 * its SHA-256 is ever persisted, so `trusted_devices.device_hash` and
 * `personal_access_tokens.device_hash` are useless to anyone who reads the
 * database — they cannot be replayed as a cookie.
 *
 * Deliberately *not* derived from the user agent or IP: a UA-derived hash is
 * shared by every user of the same browser build, which would let one visitor's
 * trusted-device record suppress MFA for a different person on the same browser.
 * IPs move between networks, which would silently drop trust on every reconnect.
 */
class DeviceFingerprint
{
    public const COOKIE = 'device_id';

    /** Long enough that a trusted device is not evicted before its 30-day expiry. */
    private const COOKIE_MINUTES = 60 * 24 * 400;

    /**
     * The hash for this request's device, issuing the cookie if absent.
     *
     * Queues the cookie on first sight, so the caller does not have to remember to.
     */
    public function hash(Request $request): string
    {
        $id = $request->cookie(self::COOKIE);

        if (! is_string($id) || $id === '') {
            $id = (string) Str::random(64);
            $this->queueCookie($id);
        }

        return hash('sha256', $id);
    }

    /**
     * The hash for this request's device, or null when the browser has never been
     * issued one. Used on paths that must not mint an identity as a side effect —
     * an unauthenticated MFA check should not hand out a device cookie.
     */
    public function existingHash(Request $request): ?string
    {
        $id = $request->cookie(self::COOKIE);

        return is_string($id) && $id !== '' ? hash('sha256', $id) : null;
    }

    /**
     * A short human label for the sessions list — "Chrome on Windows".
     *
     * Intentionally coarse. This is a recognition aid so a user can spot a session
     * that is not theirs, not device analytics, and the parsing stays trivial
     * rather than dragging in a UA-parsing dependency that needs constant updates.
     */
    public function label(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            // Safari must be tested last: Chrome and Edge both carry "Safari".
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : "{$browser} on {$platform}";
    }

    private function queueCookie(string $id): void
    {
        Cookie::queue(cookie(
            self::COOKIE,
            $id,
            self::COOKIE_MINUTES,
            '/',
            null,
            app()->isProduction(),
            true,   // httpOnly — never needs to be read by JavaScript
            false,
            'Lax',
        ));
    }
}
