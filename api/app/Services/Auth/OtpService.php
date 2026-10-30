<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Enums\Feature;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\Sms\SmsNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Issue and verify one-time login codes (PHASE_00 S02).
 *
 * A code goes by text message through `SmsSender`, or by email. Each channel
 * says whether it can deliver: the `log` SMS driver and the `log`/`array`
 * mailers report themselves unavailable, which is what keeps the endpoint from
 * claiming to have sent something it did not.
 */
class OtpService
{
    public const CODE_LENGTH = 6;

    public const TTL_MINUTES = 5;

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    /** Codes a user may have outstanding before new requests are refused. */
    private const MAX_OUTSTANDING = 3;

    /**
     * Wrong codes a user may enter in FAILURE_WINDOW seconds. The fifth spends
     * every outstanding code and refuses even a right one until the window
     * passes: without it a 6-digit code could be guessed at the IP limit's
     * pace for its whole life (security review 2026-10-30).
     */
    public const MAX_FAILURES = 5;

    public const FAILURE_WINDOW = 900;

    /** Mailers that accept a message without delivering it anywhere. */
    private const UNDELIVERED_MAILERS = ['log', 'array'];

    public function __construct(
        private readonly SmsSender $sms,
        private readonly FeatureGate $features,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function isAvailable(string $channel = self::CHANNEL_SMS): bool
    {
        // Switched off by the platform reads as no mail: the sign-in page hides
        // the button, and a request is told to use the password instead.
        if ($channel === self::CHANNEL_EMAIL) {
            return $this->mailConfigured()
                && $this->features->allowsFor($this->currentTenant->get(), Feature::EmailSignInCodes);
        }

        return $this->sms->isAvailable();
    }

    /** Whether this install can send mail at all, whatever the platform switch says. */
    public function mailConfigured(): bool
    {
        return ! in_array(config('mail.default'), self::UNDELIVERED_MAILERS, true);
    }

    /**
     * Issue a code and send it. Returns false when it could not be delivered.
     *
     * Any previously outstanding code for the same purpose is invalidated, so a
     * user always has exactly one live code and an attacker cannot widen the
     * guessing surface by requesting many at once.
     */
    public function issue(User $user, string $purpose = OtpCode::PURPOSE_LOGIN, string $channel = self::CHANNEL_SMS): bool
    {
        $reachable = $channel === self::CHANNEL_EMAIL
            ? $user->email !== null && $user->email !== ''
            : $user->phone !== null && SmsNumber::isValid($user->phone);

        if (! $reachable) {
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

        $sent = $channel === self::CHANNEL_EMAIL
            ? $this->sendByEmail($user, $code)
            : $this->sms->send((string) $user->phone, __('auth.otp_message', [
                'code' => $code,
                'minutes' => self::TTL_MINUTES,
            ]));

        AuditLog::record('user.otp_issued', $user, ['purpose' => $purpose, 'channel' => $channel, 'delivered' => $sent]);

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
        $failures = 'otp-failures:'.$user->id.':'.$purpose;

        if (RateLimiter::tooManyAttempts($failures, self::MAX_FAILURES)) {
            AuditLog::record('user.otp_failed', $user, ['purpose' => $purpose, 'locked' => true]);

            return false;
        }

        $candidates = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code)) {
                $candidate->forceFill(['used_at' => now()])->save();
                RateLimiter::clear($failures);

                AuditLog::record('user.otp_verified', $user, ['purpose' => $purpose]);

                return true;
            }
        }

        if (RateLimiter::hit($failures, self::FAILURE_WINDOW) >= self::MAX_FAILURES) {
            OtpCode::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        AuditLog::record('user.otp_failed', $user, ['purpose' => $purpose]);

        return false;
    }

    /**
     * Sent now and never retried later — see SignInCodeNotification. A failure
     * is logged and reported as not delivered; the caller is never thrown at.
     */
    private function sendByEmail(User $user, string $code): bool
    {
        try {
            $user->notifyNow(new SignInCodeNotification(
                $code,
                self::TTL_MINUTES,
                (string) ($user->tenant->name ?? 'ETHR'),
            ));

            return true;
        } catch (Throwable $e) {
            Log::error('Sign-in code email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
