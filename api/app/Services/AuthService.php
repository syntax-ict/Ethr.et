<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Events\TenantCreated;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /** Lifetime of the pre-MFA challenge token (PHASE_00 S03: 5-minute expiry). */
    public const MFA_TOKEN_MINUTES = 5;

    /** Lifetime of a full browser session, refreshed by POST /auth/refresh. */
    public const SESSION_MINUTES = 15;

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DeviceFingerprint $fingerprint,
        private readonly TrustedDeviceService $trustedDevices,
        private readonly SessionCookie $sessionCookie,
    ) {}

    public function registerTenant(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['organization_name'],
                'subdomain' => $data['subdomain'],
                'type' => $data['organization_type'] ?? null,
                'status' => TenantStatus::TRIAL,
                'trial_ends_at' => now()->addMonths(6),
            ]);

            $this->currentTenant->set($tenant);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'email' => $data['admin_email'],
                'phone' => $data['admin_phone'] ?? null,
                'password' => Hash::make($data['password']),
                'password_changed_at' => now(),
                'role' => UserRole::TENANT_ADMIN,
                'status' => 'active',
                'locale' => 'en',
            ]);

            $starterPlan = Plan::where('slug', 'starter')->first();
            if ($starterPlan) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $starterPlan->id,
                    'status' => 'trial',
                    'current_period_start' => now(),
                    'current_period_end' => $tenant->trial_ends_at,
                ]);
            }

            AuditLog::record('tenant.registered', $tenant, [
                'admin_email' => $user->email,
            ]);

            $token = $user->createToken('auth', ['*'], now()->addMinutes(self::SESSION_MINUTES));

            TenantCreated::dispatch($tenant, $user);

            $this->sessionCookie->issue($token->plainTextToken, self::SESSION_MINUTES * 60);

            return [
                'user' => $user,
                'tenant' => $tenant,
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => self::SESSION_MINUTES * 60,
            ];
        });
    }

    public function login(User $user): array
    {
        $request = request();
        $deviceHash = $this->fingerprint->hash($request);

        // Previously this deleted *every* token on each login, which capped a user
        // at exactly one session and made the S03 active-sessions list unable to
        // show more than one row — there was nothing to manage or revoke.
        //
        // Scoped to this device instead: signing in again on the same browser
        // replaces that browser's session (so rows cannot accumulate, which is what
        // the blanket delete was really protecting against), while a phone and a
        // laptop can now hold concurrent sessions as S03 requires. Expired tokens
        // from any device are swept at the same time.
        $user->tokens()->where('device_hash', $deviceHash)->delete();
        $user->tokens()->where('expires_at', '<', now())->delete();

        // A browser the user previously trusted skips the MFA prompt for 30 days.
        $mfaRequired = $user->mfa_enabled && ! $this->trustedDevices->isTrusted($user, $request);

        // When MFA is still owed, the token issued here is a short-lived
        // *challenge* credential, not a full session: 5 minutes rather than 15,
        // so an intercepted pre-MFA token has a much smaller window. It is
        // exchanged for the real 15-minute token by POST /auth/mfa/verify.
        $ttlMinutes = $mfaRequired ? self::MFA_TOKEN_MINUTES : self::SESSION_MINUTES;

        // Abilities, not just lifetime, distinguish a challenge credential from a
        // session. Issued with `['*']` it was a full-power token that merely
        // expired sooner — so a password alone reached every endpoint for five
        // minutes without the second factor ever being supplied.
        // `RejectUnverifiedMfaToken` refuses this ability everywhere except the
        // endpoints that complete or abandon the sign-in.
        $abilities = $mfaRequired ? [RejectUnverifiedMfaToken::CHALLENGE_ABILITY] : ['*'];

        $token = $user->createToken('auth', $abilities, now()->addMinutes($ttlMinutes));

        $this->stampSessionMetadata($token->accessToken, $request, $deviceHash);

        $user->update(['last_login_at' => now()]);

        AuditLog::record('user.login', $user);

        $data = [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'mfa_required' => $mfaRequired,
            // Advisory, not a block: the token is still issued so the user can
            // reach the change-password endpoint. Refusing to authenticate would
            // lock them out of the only screen that can clear the condition.
            // Always present, so clients can branch without probing for the key.
            'password_expired' => PasswordPolicy::isExpired(
                $this->currentTenant->get(),
                $user->password_changed_at,
            ),
        ];

        if ($mfaRequired) {
            // Named separately from `access_token` so a client can tell a challenge
            // credential from a session one. Same value — clients that only read
            // `access_token` (all current ones) are unaffected.
            $data['mfa_token'] = $token->plainTextToken;
            $data['mfa_token_expires_in'] = self::MFA_TOKEN_MINUTES * 60;
        }

        $this->sessionCookie->issue($token->plainTextToken, $ttlMinutes * 60);

        // `LoginRequest::authenticate()` calls `Auth::login()` the moment the
        // password checks out — before MFA is considered at all. Sanctum's
        // stateful guard then authenticates same-origin requests from that
        // session as a TransientToken, which carries no abilities, so restricting
        // the challenge *token* closes only the Bearer path: a browser could
        // simply navigate away from the MFA screen and keep using the session.
        //
        // So the stateful login is undone until the second factor arrives. What
        // remains is the challenge token in the cookie, which is
        // ability-restricted and which `RejectUnverifiedMfaToken` refuses
        // everywhere except the endpoints that finish or abandon the sign-in.
        // MfaVerifyController re-establishes the session once a code is accepted.
        //
        // Belt and braces: the session flag is also set where a session exists,
        // so a stateful request can never outlive the guard reset either.
        if ($mfaRequired) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->put(RejectUnverifiedMfaToken::SESSION_FLAG, true);
            }
        } elseif ($request->hasSession()) {
            $request->session()->forget(RejectUnverifiedMfaToken::SESSION_FLAG);
        }

        return $data;
    }

    /**
     * Carry the session's origin onto the token so the sessions list can name it.
     *
     * `save()` on the token model rather than passing to createToken(), which has
     * no hook for extra columns.
     */
    private function stampSessionMetadata(mixed $accessToken, Request $request, string $deviceHash): void
    {
        if (! $accessToken instanceof Model) {
            return;
        }

        $accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_hash' => $deviceHash,
        ])->save();
    }

    public function refreshToken(User $user): array
    {
        $currentToken = $user->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        return [
            'access_token' => $this->issueSession($user),
            'token_type' => 'Bearer',
            'expires_in' => self::SESSION_MINUTES * 60,
        ];
    }

    /**
     * Hand a user a fresh browser session — token, device metadata and cookie.
     *
     * Shared with exiting impersonation, which has to put the super admin back
     * into their own identity once the impersonation token is revoked: the same
     * act as signing in, minus the credential check and the login audit entry.
     * Returns the plaintext token for API clients that read it from the body.
     */
    public function issueSession(User $user): string
    {
        $request = request();

        $token = $user->createToken('auth', ['*'], now()->addMinutes(self::SESSION_MINUTES));

        $this->stampSessionMetadata($token->accessToken, $request, $this->fingerprint->hash($request));
        $this->sessionCookie->issue($token->plainTextToken, self::SESSION_MINUTES * 60);

        return $token->plainTextToken;
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        $this->sessionCookie->clear();

        AuditLog::record('user.logout', $user);
    }
}
