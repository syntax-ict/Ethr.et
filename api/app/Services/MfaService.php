<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
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
        if (! $this->verify(decrypt($user->mfa_secret), $code)) {
            return false;
        }

        $user->update([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ]);

        AuditLog::record('user.mfa_disabled', $user);

        return true;
    }
}
