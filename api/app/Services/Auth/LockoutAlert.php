<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * PHASE_00 S02: "account lockout after 10 failed attempts (15-min cooldown,
 * notify tenant admin)". RateLimitLoginAttempts enforces the lockout; this is
 * the "notify" half. It used to live in LoginAttemptService, which nothing
 * called — so the alert existed, was tested, and never ran.
 *
 * Sent on the request that trips the lockout — the attacker's tenth failed
 * attempt, never a legitimate user's — while the tenant is resolved and its
 * global scope applies. Best-effort: a mail outage is logged, never raised.
 */
final class LockoutAlert
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function send(string $identifier, string $ip, int $minutes): void
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            // The platform host has no tenant admins to tell.
            return;
        }

        try {
            $admins = User::query()
                ->whereIn('role', [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN])
                ->where('status', 'active')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SystemAlertNotification(
                __('auth.lockout_alert_title'),
                __('auth.lockout_alert_body', ['identifier' => $identifier, 'ip' => $ip, 'minutes' => $minutes]),
                ['identifier' => $identifier, 'ip' => $ip, 'tenant' => $tenant->public_id],
            ));
        } catch (\Throwable $e) {
            Log::warning('Account-lockout alert could not be delivered', [
                'error' => $e->getMessage(),
                'tenant' => $tenant->public_id,
            ]);
        }
    }
}
