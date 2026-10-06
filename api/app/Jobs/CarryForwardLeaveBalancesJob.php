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
 * Rolls unused leave from one balance year into the next, capped per leave type
 * at `max_carry_days`.
 *
 * `LeaveBalance.year` is the Gregorian calendar year — `accrueMonthly()` keys
 * off `now()->year` and accrues on a Jan–Dec curve — so the roll-over runs on
 * 1 January, keeping carry-over in step with accrual.
 *
 * Dispatched per-tenant. Re-running is safe: the destination balance's
 * `carried_days` is assigned, not incremented.
 */
class CarryForwardLeaveBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $fromYear,
        private readonly int $toYear,
    ) {}

    public function handle(LeaveBalanceService $balances, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $carried = $balances->carryForward($this->tenantId, $this->fromYear, $this->toYear);

        Log::info('CarryForwardLeaveBalances: carry-over complete', [
            'tenant_id' => $this->tenantId,
            'from_year' => $this->fromYear,
            'to_year' => $this->toYear,
            'balances_carried' => $carried,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Employees keep their prior-year balance rows, so nothing is lost —
        // the job can be replayed from the admin failed-jobs dashboard.
        Log::error('CarryForwardLeaveBalancesJob failed', [
            'tenant_id' => $this->tenantId,
            'from_year' => $this->fromYear,
            'to_year' => $this->toYear,
            'error' => $exception->getMessage(),
        ]);
    }
}
