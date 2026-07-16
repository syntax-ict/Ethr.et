<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisableMfaRequest;
use App\Http\Requests\Auth\EnableMfaRequest;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaSetupController extends Controller
{
    public function __construct(private readonly MfaService $mfaService) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-already-enabled',
                'title' => 'MFA Already Enabled',
                'status' => 409,
                'detail' => 'Two-factor authentication is already enabled.',
            ], 409);
        }

        $secret = $this->mfaService->generateSecret();
        $qrUrl = $this->mfaService->getQrCodeUrl($user, $secret);

        return response()->json([
            'secret' => $secret,
            'qr_code_url' => $qrUrl,
        ]);
    }

    public function enable(EnableMfaRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-already-enabled',
                'title' => 'MFA Already Enabled',
                'status' => 409,
                'detail' => 'Two-factor authentication is already enabled.',
            ], 409);
        }

        if (! $this->mfaService->enable($user, $request->input('secret'), $request->input('code'))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-mfa-code',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.mfa_invalid'),
            ], 422);
        }

        return response()->json([
            'message' => __('auth.mfa_enabled'),
        ]);
    }

    public function disable(DisableMfaRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-not-enabled',
                'title' => 'MFA Not Enabled',
                'status' => 409,
                'detail' => 'Two-factor authentication is not enabled.',
            ], 409);
        }

        if (! $this->mfaService->disable($user, $request->input('code'))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-mfa-code',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.mfa_invalid'),
            ], 422);
        }

        return response()->json([
            'message' => __('auth.mfa_disabled'),
        ]);
    }
}
