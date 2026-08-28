<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class LoginAttemptService
{
    private const MAX_ATTEMPTS = 10;

    private const LOCKOUT_MINUTES = 15;

    public function __construct(private ?Repository $cache = null)
    {
        $this->cache ??= Cache::store('default');
    }

    public function recordFailedAttempt(string $email, string $ip, Tenant $tenant): void
    {
        $key = $this->getAttemptKey($email, $ip, $tenant->public_id);
        $attempts = (int) $this->cache->get($key, 0);

        $this->cache->put($key, $attempts + 1, now()->addMinutes(self::LOCKOUT_MINUTES));

        if ($attempts + 1 >= self::MAX_ATTEMPTS) {
            $this->lockoutAccount($email, $ip, $tenant);
        }
    }

    public function recordSuccessfulAttempt(string $email, string $ip, Tenant $tenant): void
    {
        $key = $this->getAttemptKey($email, $ip, $tenant->public_id);
        $this->cache->forget($key);

        $lockoutKey = $this->getLockoutKey($email, $ip, $tenant->public_id);
        $this->cache->forget($lockoutKey);
    }

    public function isLockedOut(string $email, string $ip, Tenant $tenant): bool
    {
        $key = $this->getLockoutKey($email, $ip, $tenant->public_id);

        return (bool) $this->cache->get($key);
    }

    public function getAttemptsRemaining(string $email, string $ip, Tenant $tenant): int
    {
        $key = $this->getAttemptKey($email, $ip, $tenant->public_id);
        $attempts = (int) $this->cache->get($key, 0);

        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    public function getLockedOutMinutesRemaining(string $email, string $ip, Tenant $tenant): int
    {
        $key = $this->getLockoutKey($email, $ip, $tenant->public_id);

        $ttl = $this->cache->getStore()->connection()->pttl($key);

        return (int) ceil($ttl / 60000); // Convert milliseconds to minutes
    }

    private function lockoutAccount(string $email, string $ip, Tenant $tenant): void
    {
        $key = $this->getLockoutKey($email, $ip, $tenant->public_id);

        // Only notify on the transition into lockout, not on every subsequent
        // failed attempt while already locked — otherwise a running brute-force
        // attack becomes a mail flood aimed at the very admins who need to read it.
        $alreadyLockedOut = (bool) $this->cache->get($key);

        $this->cache->put($key, true, now()->addMinutes(self::LOCKOUT_MINUTES));

        if (! $alreadyLockedOut) {
            $this->notifyTenantAdmins($email, $ip, $tenant);
        }
    }

    /**
     * PHASE_00 S02 requires the tenant admin to be told when an account locks out.
     * Delivery is best-effort: a mail or broadcast outage must not turn the
     * lockout itself into an exception on the login path.
     */
    private function notifyTenantAdmins(string $email, string $ip, Tenant $tenant): void
    {
        try {
            $admins = User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('role', [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN])
                ->where('status', 'active')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SystemAlertNotification(
                __('auth.lockout_alert_title'),
                __('auth.lockout_alert_body', [
                    'identifier' => $email,
                    'ip' => $ip,
                    'minutes' => self::LOCKOUT_MINUTES,
                ]),
                ['identifier' => $email, 'ip' => $ip, 'tenant' => $tenant->public_id],
            ));
        } catch (\Throwable $e) {
            Log::warning('Account-lockout alert could not be delivered', [
                'error' => $e->getMessage(),
                'tenant' => $tenant->public_id,
            ]);
        }
    }

    private function getAttemptKey(string $email, string $ip, string $tenantPublicId): string
    {
        return "login_attempts:{$email}:{$ip}:{$tenantPublicId}";
    }

    private function getLockoutKey(string $email, string $ip, string $tenantPublicId): string
    {
        return "login_lockout:{$email}:{$ip}:{$tenantPublicId}";
    }
}
