<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tops up monthly-accrual leave balances to the entitlement earned so far this
 * year. Runs on the 1st of each month.
 *
 * Dispatched per-tenant so one tenant's failure cannot stall the others.
 * `accrueMonthly()` computes a target from the month number rather than adding
 * a fixed increment, so re-running it — on retry, or after a missed month — is
 * safe and self-correcting.
 */
class AccrueLeaveBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(private readonly int $tenantId) {}

    public function handle(LeaveBalanceService $balances, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $accrued = $balances->accrueMonthly($this->tenantId);

        Log::info('AccrueLeaveBalances: accrual complete', [
            'tenant_id' => $this->tenantId,
            'balances_updated' => $accrued,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Safe to leave until next month: the next run targets the same
        // cumulative entitlement and will catch the tenant up.
        Log::error('AccrueLeaveBalancesJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
