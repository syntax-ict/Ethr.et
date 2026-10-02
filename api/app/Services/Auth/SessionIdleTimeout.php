<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\TenantSecurityPolicy;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The tenant idle timeout, applied to bearer tokens.
 *
 * Registered with `Sanctum::authenticateAccessTokensUsing()` in
 * `AppServiceProvider`, so it runs inside the guard — *before* Sanctum stamps
 * `last_used_at` with the current request, which is the only point at which the
 * previous activity can still be read. A middleware would see `last_used_at`
 * already set to now.
 *
 * An idle token is refused and deleted, so it cannot be refreshed either:
 * `POST /auth/refresh` authenticates with the token it replaces. The caller
 * gets the ordinary 401.
 *
 * The browser's session half is `EnforceSessionIdleTimeout`.
 */
final class SessionIdleTimeout
{
    public static function tokenIsIdle(PersonalAccessToken $token): bool
    {
        $user = $token->tokenable;
        if (! $user instanceof User) {
            return false;
        }

        $policy = TenantSecurityPolicy::forUser($user);
        if ($policy === null) {
            return false;
        }

        $lastActivity = $token->last_used_at ?? $token->created_at;
        if ($lastActivity === null) {
            return false;
        }

        if ($lastActivity->greaterThan(now()->subMinutes($policy->idleTimeoutMinutes()))) {
            return false;
        }

        $token->delete();

        return true;
    }
}
