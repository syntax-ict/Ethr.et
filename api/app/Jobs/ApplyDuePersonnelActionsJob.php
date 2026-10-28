<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\PersonnelActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Applies one organisation's scheduled personnel actions whose effective date
 * has come, in that organisation's own zone.
 *
 * Dispatched hourly per tenant, so a change dated tomorrow takes effect within
 * the first hour of the organisation's day, and a run missed while the cron
 * caller was down is caught up by the next: it applies everything due, not
 * only what fell due today.
 */
class ApplyDuePersonnelActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly int $tenantId,
    ) {}

    public function handle(PersonnelActionService $actions, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $applied = $actions->applyDue();

        if ($applied > 0) {
            Log::info('ApplyDuePersonnelActions: applied scheduled actions', [
                'tenant_id' => $this->tenantId,
                'applied' => $applied,
            ]);
        }
    }
}
