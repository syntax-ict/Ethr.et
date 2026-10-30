<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\MfaResetNotification;
use App\Support\Mail\CriticalMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function getQrCodeUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            // An employee activated in person has no email; the authenticator
            // entry is labelled with the sign-in handle they do have.
            $user->email ?? $user->username ?? $user->public_id,
            $secret
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Wrong codes a user may enter in FAILURE_WINDOW seconds before every code
     * is refused until the window passes. Without it the sign-in challenge took
     * 300 guesses a minute against a code with roughly three valid values in a
     * million, about even odds in a day (security review 2026-10-30).
     */
    public const MAX_FAILURES = 5;

    public const FAILURE_WINDOW = 900;

    public function lockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->failureKey($user), self::MAX_FAILURES);
    }

    public function secondsUntilUnlocked(User $user): int
    {
        return RateLimiter::availableIn($this->failureKey($user));
    }

    /**
     * Check a code against the user's own secret, counting wrong ones toward
     * the lockout. With `$singleUse`, an accepted code cannot be accepted again
     * while it is still inside its time window, so a code seen over someone's
     * shoulder cannot finish a second sign-in.
     */
    public function verifyFor(User $user, string $code, bool $singleUse = false): bool
    {
        if ($this->lockedOut($user)) {
            return false;
        }

        // Plaintext already: the `encrypted` cast decrypts on read.
        $valid = $this->verify((string) $user->mfa_secret, $code)
            && (! $singleUse || Cache::add('mfa-used:'.$user->id.':'.$code, true, 120));

        if (! $valid) {
            RateLimiter::hit($this->failureKey($user), self::FAILURE_WINDOW);

            return false;
        }

        RateLimiter::clear($this->failureKey($user));

        return true;
    }

    private function failureKey(User $user): string
    {
        return 'mfa-failures:'.$user->id;
    }

    public function enable(User $user, string $secret, string $code): bool
    {
        if (! $this->verify($secret, $code)) {
            return false;
        }

        $user->update([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ]);

        AuditLog::record('user.mfa_enabled', $user);

        return true;
    }

    public function disable(User $user, string $code): bool
    {
        if (! $this->verifyFor($user, $code)) {
            return false;
        }

        $user->update([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ]);

        AuditLog::record('user.mfa_disabled', $user);

        return true;
    }

    /**
     * Turn MFA off for someone who lost their authenticator, without a code:
     * the way back that did not exist until 2026-10-09. Their trusted browsers
     * are forgotten too, and they are emailed, because whoever can do this can
     * also use it to get around MFA. `$by` names who did it in the audit row.
     *
     * Returns whether the email went. A mail failure does not undo the reset or
     * fail the request: the rehearsal, which has no SMTP host, answered 500
     * after ten seconds although the reset had happened. Activation emails are
     * handled the same way (UserProvisioningService::sendActivationLink).
     */
    public function reset(User $user, string $by): bool
    {
        $user->update([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ]);

        TrustedDevice::where('user_id', $user->id)->delete();

        AuditLog::record('user.mfa_reset', $user, ['by' => $by]);

        // Sent now; a failure queues retries (CriticalMail) and is reported
        // as not sent, as it always was.
        return CriticalMail::send($user, new MfaResetNotification);
    }
}
