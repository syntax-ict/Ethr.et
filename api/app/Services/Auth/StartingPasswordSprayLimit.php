<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * A limit on guessing a batch's starting password, per organisation.
 *
 * Everyone in a batch shares one password, so a guess that is wrong for one
 * account is wrong for all of them. Tried once against each account, it never
 * reaches the per-identifier lockout (RateLimitLoginAttempts: 10 tries on one
 * identifier from one address). So failed sign-ins against accounts that still
 * have a starting password are counted together, whatever the identifier or
 * address. Past the limit, those accounts cannot sign in for a cooling period
 * and the organisation's administrators are told once.
 *
 * Accounts with a password of their own are not counted and not held back.
 *
 * While cooling, a sign-in is answered exactly as a wrong password is
 * (LoginRequest). A distinct answer would tell a guesser which employee
 * numbers still have a starting password, for free.
 *
 * The cost, accepted with the feature: anyone who can guess employee numbers
 * can hold back first sign-ins for the cooling period. It does not touch
 * anyone who has already chosen a password.
 */
final class StartingPasswordSprayLimit
{
    public const MAX_FAILURES = 20;

    public const WINDOW_SECONDS = 900;

    public const COOLDOWN_SECONDS = 900;

    public function __construct(private readonly RateLimiter $limiter) {}

    /** Whether this organisation's starting passwords are held back right now. */
    public function cooling(int $tenantId): bool
    {
        return Cache::has($this->cooldownKey($tenantId));
    }

    /** Count one failed sign-in against an account that still has a starting password. */
    public function recordFailure(Tenant $tenant, string $ip): void
    {
        // hit() returns the new count, so equality is the one request that
        // crosses the limit: the administrators hear once.
        if ($this->limiter->hit($this->failureKey($tenant->id), self::WINDOW_SECONDS) !== self::MAX_FAILURES) {
            return;
        }

        Cache::put($this->cooldownKey($tenant->id), true, self::COOLDOWN_SECONDS);

        AuditLog::record('user.starting_password_cooldown', $tenant, [
            'failures' => self::MAX_FAILURES,
            'minutes' => intdiv(self::COOLDOWN_SECONDS, 60),
            'ip' => $ip,
        ]);

        $this->alert($tenant);
    }

    /** Best-effort, as LockoutAlert is: a mail outage is logged, never raised. */
    private function alert(Tenant $tenant): void
    {
        try {
            $admins = User::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('role', [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN])
                ->where('status', 'active')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SystemAlertNotification(
                __('auth.starting_password_alert_title'),
                __('auth.starting_password_alert_body', [
                    'failures' => self::MAX_FAILURES,
                    'minutes' => intdiv(self::COOLDOWN_SECONDS, 60),
                ]),
                ['tenant' => $tenant->public_id],
            ));
        } catch (\Throwable $e) {
            Log::warning('Starting-password alert could not be delivered', [
                'error' => $e->getMessage(),
                'tenant' => $tenant->public_id,
            ]);
        }
    }

    private function failureKey(int $tenantId): string
    {
        return "starting-password-failures:{$tenantId}";
    }

    private function cooldownKey(int $tenantId): string
    {
        return "starting-password-cooldown:{$tenantId}";
    }
}
