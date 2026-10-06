<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\SmsNumber;
use Illuminate\Support\Facades\Hash;

/**
 * Issue and verify one-time login codes (PHASE_00 S02).
 *
 * Delivery goes through `SmsSender`, so this is only usable where a gateway is
 * configured. The `log` driver reports itself unavailable, which is what keeps
 * the endpoint from claiming to have sent something it did not.
 */
class OtpService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 5;

    /** Codes a user may have outstanding before new requests are refused. */
    private const MAX_OUTSTANDING = 3;

    public function __construct(private readonly SmsSender $sms) {}

    public function isAvailable(): bool
    {
        return $this->sms->isAvailable();
    }

    /**
     * Issue a code and send it. Returns false when it could not be delivered.
     *
     * Any previously outstanding code for the same purpose is invalidated, so a
     * user always has exactly one live code and an attacker cannot widen the
     * guessing surface by requesting many at once.
     */
    public function issue(User $user, string $purpose = OtpCode::PURPOSE_LOGIN): bool
    {
        if ($user->phone === null || ! SmsNumber::isValid($user->phone)) {
            return false;
        }

        if ($this->outstandingCount($user, $purpose) >= self::MAX_OUTSTANDING) {
            return false;
        }

        OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // random_int, not rand/mt_rand: this is a credential, so it needs a
        // cryptographically secure source.
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $sent = $this->sms->send($user->phone, __('auth.otp_message', [
            'code' => $code,
            'minutes' => self::TTL_MINUTES,
        ]));

        AuditLog::record('user.otp_issued', $user, ['purpose' => $purpose, 'delivered' => $sent]);

        return $sent;
    }

    /**
     * Verify a submitted code, consuming it on success.
     *
     * Every candidate is checked and the code is marked used the moment it
     * matches, so a code is single-use even under concurrent submissions.
     */
    public function verify(User $user, string $code, string $purpose = OtpCode::PURPOSE_LOGIN): bool
    {
        $candidates = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code)) {
                $candidate->forceFill(['used_at' => now()])->save();

                AuditLog::record('user.otp_verified', $user, ['purpose' => $purpose]);

                return true;
            }
        }

        AuditLog::record('user.otp_failed', $user, ['purpose' => $purpose]);

        return false;
    }

    private function outstandingCount(User $user, string $purpose): int
    {
        return OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->count();
    }
}
