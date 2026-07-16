<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

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
        $this->cache->put($key, true, now()->addMinutes(self::LOCKOUT_MINUTES));
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
