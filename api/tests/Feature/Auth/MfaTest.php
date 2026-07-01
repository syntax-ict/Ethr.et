<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Services\MfaService;
use PragmaRX\Google2FA\Google2FA;

describe('MFA setup', function () {
    it('generates a secret and QR code URL', function () {
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $response = $this->postJson('/api/v1/auth/mfa/setup');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'qr_code_url']);

        expect(strlen($response->json('secret')))->toBeGreaterThanOrEqual(16);
    });

    it('rejects setup when MFA is already enabled', function () {
        actingAsUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);

        $this->postJson('/api/v1/auth/mfa/setup')
            ->assertStatus(409);
    });

    it('enables MFA with valid code', function () {
        $user = actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $mfaService = app(MfaService::class);
        $secret = $mfaService->generateSecret();

        $google2fa = new Google2FA;
        $code = $google2fa->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/auth/mfa/enable', [
            'secret' => $secret,
            'code' => $code,
        ]);

        $response->assertOk();

        $user->refresh();
        expect($user->mfa_enabled)->toBeTrue();
    });

    it('rejects MFA enable with invalid code', function () {
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $mfaService = app(MfaService::class);
        $secret = $mfaService->generateSecret();

        $this->postJson('/api/v1/auth/mfa/enable', [
            'secret' => $secret,
            'code' => '000000',
        ])->assertStatus(422);
    });

    it('disables MFA with valid code', function () {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = actingAsUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'mfa_secret' => encrypt($secret),
        ]);

        $google2fa = new Google2FA;
        $code = $google2fa->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/auth/mfa/disable', [
            'code' => $code,
        ]);

        $response->assertOk();

        $user->refresh();
        expect($user->mfa_enabled)->toBeFalse();
    });

    it('rejects MFA disable with invalid code', function () {
        actingAsUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);

        $this->postJson('/api/v1/auth/mfa/disable', [
            'code' => '000000',
        ])->assertStatus(422);
    });
});

describe('MFA verify', function () {
    it('verifies MFA code and returns new token', function () {
        $secret = 'JBSWY3DPEHPK3PXP';
        actingAsUser([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt($secret),
        ]);

        $google2fa = new Google2FA;
        $code = $google2fa->getCurrentOtp($secret);

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    });

    it('rejects invalid MFA verification code', function () {
        actingAsUser([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);

        $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => '000000',
        ])->assertStatus(422);
    });

    it('rejects MFA verify when MFA is not enabled', function () {
        actingAsUser(['mfa_enabled' => false]);

        $this->postJson('/api/v1/auth/mfa/verify', [
            'code' => '123456',
        ])->assertStatus(409);
    });
});
