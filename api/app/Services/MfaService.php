<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\MfaResetNotification;
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
     */
    public function reset(User $user, string $by): void
    {
        $user->update([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ]);

        TrustedDevice::where('user_id', $user->id)->delete();

        AuditLog::record('user.mfa_reset', $user, ['by' => $by]);

        $user->notify(new MfaResetNotification);
    }
}
