<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisableMfaRequest;
use App\Http\Requests\Auth\EnableMfaRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaService;
use App\Support\TenantSecurityPolicy;
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

        if ($this->enrolmentDisabledByPolicy($user)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-disabled-by-policy',
                'title' => 'MFA Not Offered',
                'status' => 403,
                'detail' => __('auth.mfa_disabled_by_policy'),
            ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
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

        if ($this->enrolmentDisabledByPolicy($user)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-disabled-by-policy',
                'title' => 'MFA Not Offered',
                'status' => 403,
                'detail' => __('auth.mfa_disabled_by_policy'),
            ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
        }

        if (! $this->mfaService->enable($user, $request->input('secret'), $request->input('code'))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-mfa-code',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.mfa_invalid'),
            ], 422);
        }

        AuditLog::record('auth.mfa_enabled', $user);

        return response()->json([
            'message' => __('auth.mfa_enabled'),
        ]);
    }

    /**
     * A tenant whose MFA policy is "disabled" does not offer enrolment (audit
     * N6: the policy was stored and read by nothing). Enrolments made before the
     * policy changed are left alone — this never removes a second factor, and
     * `disable` stays open so a user can remove their own.
     */
    private function enrolmentDisabledByPolicy(User $user): bool
    {
        return TenantSecurityPolicy::forUser($user)?->forbidsMfaEnrolment() ?? false;
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

        AuditLog::record('auth.mfa_disabled', $user);

        return response()->json([
            'message' => __('auth.mfa_disabled'),
        ]);
    }
}
