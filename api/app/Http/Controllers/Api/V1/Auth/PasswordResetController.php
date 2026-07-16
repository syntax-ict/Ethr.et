<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Password reset flow:
 *   1. POST /auth/password/forgot  — request reset link by email (public)
 *   2. POST /auth/password/reset   — set new password using emailed token (public)
 *   3. POST /auth/password/change  — change password when logged in (authenticated)
 *
 * Requires an X-Tenant / tenant field on the shared-URL flow because tenant
 * identity has to be established BEFORE we can scope the user lookup.
 */
class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {

        $tenant = app(CurrentTenant::class);
        if (! $tenant->resolved()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Tenant Required',
                'status' => 422,
                'detail' => 'Provide your organization subdomain to request a password reset.',
                'errors' => ['tenant' => ['Organization subdomain is required.']],
            ], 422);
        }

        // Rate-limit per email+IP to prevent enumeration + spam
        $key = 'password-forgot|'.Str::lower($request->input('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/rate-limit',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => 'Too many password reset attempts. Try again in a few minutes.',
            ], 429);
        }
        RateLimiter::hit($key, 300);

        // Look up user scoped to tenant. Don't reveal whether the email exists.
        $user = User::withoutGlobalScopes()
            ->where('email', $request->input('email'))
            ->where('tenant_id', $tenant->id())
            ->first();

        if ($user) {
            // Generate token via Laravel's password broker so it's stored properly
            $token = Password::broker()->createToken($user);

            try {
                $user->notify(new PasswordResetLinkNotification($token, $tenant->get()->subdomain));
                AuditLog::record('user.password_reset_requested', $user);
            } catch (\Throwable $e) {
                Log::warning('Password reset email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Same response whether user exists or not — prevents enumeration
        return response()->json([
            'message' => 'If an account with that email exists in this organization, a reset link has been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {

        $tenant = app(CurrentTenant::class);
        if (! $tenant->resolved()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Tenant Required',
                'status' => 422,
                'detail' => 'Organization subdomain is required.',
                'errors' => ['tenant' => ['Organization subdomain is required.']],
            ], 422);
        }

        $user = User::withoutGlobalScopes()
            ->where('email', $request->input('email'))
            ->where('tenant_id', $tenant->id())
            ->first();

        if (! $user) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-token',
                'title' => 'Invalid Reset Link',
                'status' => 422,
                'detail' => 'This reset link is invalid or has expired.',
            ], 422);
        }

        $broker = Password::broker();

        if (! $broker->tokenExists($user, $request->input('token'))) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-token',
                'title' => 'Invalid Reset Link',
                'status' => 422,
                'detail' => 'This reset link is invalid or has expired.',
            ], 422);
        }

        // Update password + invalidate all existing tokens
        $user->update(['password' => Hash::make($request->input('password'))]);
        $user->tokens()->delete();
        $broker->deleteToken($user);

        AuditLog::record('user.password_reset_completed', $user);

        return response()->json([
            'message' => 'Password has been reset. Please sign in with your new password.',
        ]);
    }

    public function change(ChangePasswordRequest $request): JsonResponse
    {

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        DB::transaction(function () use ($user, $request) {
            $user->update(['password' => Hash::make($request->input('password'))]);

            // Revoke all other sessions but keep the current one active so the
            // caller doesn't get logged out mid-request
            $currentTokenId = $user->currentAccessToken()?->id;
            $user->tokens()
                ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
                ->delete();
        });

        AuditLog::record('user.password_changed', $user);

        return response()->json([
            'message' => 'Password changed successfully. Other active sessions have been signed out.',
        ]);
    }
}
