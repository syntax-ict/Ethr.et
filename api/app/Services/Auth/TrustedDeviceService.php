<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Trust this device" — suppress the MFA prompt on a known browser for 30 days
 * (PHASE_00 S03).
 */
class TrustedDeviceService
{
    public const TRUST_DAYS = 30;

    public function __construct(private readonly DeviceFingerprint $fingerprint) {}

    /**
     * Whether this request comes from a browser the user has trusted.
     *
     * Uses `existingHash()` rather than `hash()`: this runs on the MFA path, and
     * minting a device cookie merely because someone reached the MFA screen would
     * hand an identity to an unauthenticated caller.
     */
    public function isTrusted(User $user, Request $request): bool
    {
        $hash = $this->fingerprint->existingHash($request);

        if ($hash === null) {
            return false;
        }

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('device_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return false;
        }

        // Touch, but do not extend: trust expires 30 days after it was granted,
        // not 30 days after last use. A rolling window would make a device trusted
        // indefinitely so long as it kept signing in, which is what the fixed
        // expiry exists to prevent.
        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Remember this browser for the user. Idempotent — re-trusting an existing
     * device renews it rather than accumulating duplicate rows (the table's
     * unique(user_id, device_hash) index would reject those anyway).
     */
    public function trust(User $user, Request $request): TrustedDevice
    {
        $hash = $this->fingerprint->hash($request);

        $device = TrustedDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_hash' => $hash],
            [
                'device_name' => $this->fingerprint->label($request->userAgent()),
                'last_used_at' => now(),
                'expires_at' => now()->addDays(self::TRUST_DAYS),
            ],
        );

        AuditLog::record('user.device_trusted', $user, [
            'device_name' => $device->device_name,
        ]);

        return $device;
    }

    /**
     * Drop expired rows for this user. Called on listing so the table does not
     * accumulate dead trust records for browsers that never return.
     */
    public function pruneExpired(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
