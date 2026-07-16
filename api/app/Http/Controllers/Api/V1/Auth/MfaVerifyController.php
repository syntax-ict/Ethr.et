<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyMfaRequest;
use App\Models\AuditLog;
use App\Services\AuthService;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;

class MfaVerifyController extends Controller
{
    public function __construct(
        private readonly MfaService $mfaService,
        private readonly AuthService $authService,
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

        $result = $this->authService->refreshToken($user);

        return response()->json($result);
    }
}
