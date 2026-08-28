<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Http\Requests\Auth\VerifyMfaRequest;
use App\Models\AuditLog;
use App\Services\Auth\TrustedDeviceService;
use App\Services\AuthService;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;

class MfaVerifyController extends Controller
{
    public function __construct(
        private readonly MfaService $mfaService,
        private readonly AuthService $authService,
        private readonly TrustedDeviceService $trustedDevices,
    ) {}

    public function __invoke(VerifyMfaRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-not-enabled',
                'title' => 'MFA Not Required',
                'status' => 409,
                'detail' => 'Two-factor authentication is not enabled for this account.',
            ], 409);
        }

        $secret = decrypt($user->mfa_secret);

        if (! $this->mfaService->verify($secret, $request->input('code'))) {
            AuditLog::record('user.mfa_failed', $user);

            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-mfa-code',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.mfa_invalid'),
            ], 422);
        }

        AuditLog::record('user.mfa_verified', $user);

        // Clears the "password accepted, second factor still owed" mark that
        // RejectUnverifiedMfaToken enforces. Only here, and only after a code has
        // actually been accepted — this is the single point where the session
        // stops being a challenge and becomes a real one.
        if ($request->hasSession()) {
            $request->session()->forget(RejectUnverifiedMfaToken::SESSION_FLAG);
        }

        // "Trust this device" is honoured only after the code has actually been
        // verified — trusting on an unverified request would let an attacker who
        // reached this endpoint mark their own browser as MFA-exempt.
        $trusted = false;
        if ($request->boolean('trust_device')) {
            $this->trustedDevices->trust($user, $request);
            $trusted = true;
        }

        $result = $this->authService->refreshToken($user);
        $result['device_trusted'] = $trusted;

        if ($trusted) {
            $result['device_trusted_days'] = TrustedDeviceService::TRUST_DAYS;
        }

        return response()->json($result);
    }
}
