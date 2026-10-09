<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\MfaResetNotification;
use Illuminate\Support\Facades\Log;
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
            $user->email,
            $secret
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
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
        // Plaintext already — the `encrypted` cast decrypts on read.
        if (! $this->verify((string) $user->mfa_secret, $code)) {
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

        try {
            $user->notify(new MfaResetNotification);

            return true;
        } catch (\Throwable $e) {
            Log::warning('MFA reset email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
