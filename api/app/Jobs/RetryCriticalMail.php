<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * A sign-in email (password reset, account activation, MFA reset) whose first
 * attempt failed, tried again over the next few hours (CriticalMail).
 *
 * Encrypted on the queue: the notification carries a reset or activation
 * token, and the jobs table must not hold one in clear. The user is loaded by
 * id inside their own tenant — `tenantId` is required, the same rule the other
 * queued jobs follow (QueuedJobTenantPredicateTest) — rather than through
 * SerializesModels, which would restore the model before any tenant is set and
 * find nothing behind the fail-closed scope.
 */
final class RetryCriticalMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** One SMTP conversation; the queue's retry_after (90 s) must exceed it. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
        public readonly Notification $notification,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $currentTenant->set($tenant);

        $user = User::query()->where('tenant_id', $this->tenantId)->find($this->userId);
        if ($user === null) {
            return;
        }

        $user->notifyNow($this->notification);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Sign-in email could not be delivered after retries', [
            'user_id' => $this->userId,
            'notification' => $this->notification::class,
            'error' => $e->getMessage(),
        ]);
    }
}
