<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * OTP sign-in (PHASE_00 S02: `POST /auth/otp/request`, `POST /auth/otp/verify`).
 *
 * Both endpoints answer identically whether or not the phone belongs to a real
 * account, so neither can be used to enumerate users.
 */
class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuthService $authService,
    ) {}

    public function request(RequestOtpRequest $request): JsonResponse
    {
        // Stated plainly rather than pretending to send: without a gateway the
        // code can never arrive, and a generic "check your phone" would leave the
        // user waiting for a message that does not exist.
        if (! $this->otp->isAvailable()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/otp-unavailable',
                'title' => 'OTP Unavailable',
                'status' => 503,
                'detail' => __('auth.otp_unavailable'),
            ], 503);
        }

        $user = $this->resolveUser($request->input('phone'));

        if ($user !== null) {
            $this->otp->issue($user);
        }

        return response()->json(['message' => __('auth.otp_sent')]);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request->input('phone'));

        if ($user === null || ! $this->otp->verify($user, $request->input('code'))) {
            if ($user !== null) {
                LoginHistory::record($user, 'failed', 'invalid_otp');
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-otp',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.otp_invalid'),
            ], 422);
        }

        if ($user->status !== 'active') {
            LoginHistory::record($user, 'blocked', 'account_inactive');

            return response()->json([
                'type' => 'https://ethr.et/errors/account-inactive',
                'title' => 'Account Inactive',
                'status' => 403,
                'detail' => __('auth.account_suspended'),
            ], 403);
        }

        // LoginRequest::authenticate() calls this for the password path — without
        // it here too, OTP sign-in issues a token but never starts the session
        // cookie the browser SPA actually runs on (it never sends an Authorization
        // header). The response would look successful and every request after it
        // would 401.
        Auth::login($user);

        $result = $this->authService->login($user);

        LoginHistory::record($user, 'success');

        return response()->json($result);
    }

    /**
     * Tenant-scoped phone lookup. Returns null rather than throwing so callers
     * can keep their responses uniform for known and unknown numbers alike.
     */
    private function resolveUser(string $phone): ?User
    {
        $tenant = app(CurrentTenant::class);

        if (! $tenant->resolved()) {
            return null;
        }

        // Matched on every equivalent shape, not the raw string. `0911223344`
        // and `+251911223344` are the same subscriber, but registration stores
        // E.164 while users type the local form — an exact match found nobody
        // and, because this endpoint answers identically for unknown numbers,
        // failed silently. See App\Support\EthiopianPhone.
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id())
            ->whereIn('phone', EthiopianPhone::variants($phone))
            ->first();
    }
}
