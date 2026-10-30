<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\Sms\ConfiguredSmsSender;
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
        private readonly ConfiguredSmsSender $sms,
    ) {}

    public function request(RequestOtpRequest $request): JsonResponse
    {
        // Stated plainly rather than pretending to send: without a gateway the
        // code can never arrive, and a generic "check your phone" would leave the
        // user waiting for a message that does not exist.
        $channel = $this->channel($request->input('email'));

        if (! $this->otp->isAvailable($channel)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/otp-unavailable',
                'title' => 'OTP Unavailable',
                'status' => 503,
                'detail' => __(match (true) {
                    $channel !== OtpService::CHANNEL_EMAIL => 'auth.otp_unavailable',
                    $this->otp->mailConfigured() => 'auth.otp_email_paused',
                    default => 'auth.otp_email_unavailable',
                }),
            ], 503);
        }

        $user = $this->resolveUser($request->input('phone'), $request->input('email'));

        if ($user !== null) {
            $this->otp->issue($user, OtpCode::PURPOSE_LOGIN, $channel);
        }

        return response()->json(['message' => __('auth.otp_sent')]);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request->input('phone'), $request->input('email'));

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

        // An invitation by text tells a person with only a phone to sign in with
        // a code. The code just proved they hold that phone, which is what an
        // activation link proves for an address, so the account is activated
        // here. Only while text invitations are on, and never for an account
        // with an email: that one is activated by its link.
        if ($user->status === 'invited'
            && $this->channel($request->input('email')) === OtpService::CHANNEL_SMS
            && empty($user->email)
            && $this->sms->invitationsEnabled()) {
            $user->update(['status' => 'active', 'activated_at' => now()]);
            AuditLog::record('user.activated_by_sms_code', $user);
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

    /** An email address asks for the code by email; a phone number, by text. */
    private function channel(mixed $email): string
    {
        return is_string($email) && $email !== '' ? OtpService::CHANNEL_EMAIL : OtpService::CHANNEL_SMS;
    }

    /**
     * Tenant-scoped lookup by email or phone. Returns null rather than throwing
     * so callers can keep their responses uniform for known and unknown
     * accounts alike.
     */
    private function resolveUser(?string $phone, ?string $email = null): ?User
    {
        $tenant = app(CurrentTenant::class);

        if (! $tenant->resolved()) {
            return null;
        }

        // As password sign-in matches it (AuthIdentifierResolver::byEmail),
        // including refusing an address two accounts share.
        if ($this->channel($email) === OtpService::CHANNEL_EMAIL) {
            $matches = User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id())
                ->where('email_normalized', mb_strtolower(trim((string) $email)))
                ->limit(2)
                ->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        if ($phone === null || $phone === '') {
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
