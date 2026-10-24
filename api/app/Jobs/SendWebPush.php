<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One web push to one user, sent from the queue so a slow push service never
 * holds up the request that caused it. Production drains the queue from the
 * cron caller every few minutes, so a push may arrive that much later.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /** One request per subscribed device, each with a 10-second client timeout. */
    public int $timeout = 60;

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $payload
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly array $payload,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(CurrentTenant $currentTenant, WebPushSender $sender): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        // The subscriptions are tenant-scoped; this job sets its own tenant
        // rather than trusting whatever the previous job left (CLAUDE.md §4).
        $currentTenant->set($tenant);
        $sender->toUser($this->userId, $this->payload);
    }
}
