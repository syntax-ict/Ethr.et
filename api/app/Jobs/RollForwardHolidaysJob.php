<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates next year's holidays for one tenant: the statutory ones, and its
 * own holidays marked "recurring every year" (audit N58).
 *
 * Scheduled monthly rather than once a year, so a missed cron run or a
 * tenant created mid-year is caught up by the next one; HolidayService::
 * rollForward() is idempotent. Dispatched per tenant so one tenant's failure
 * cannot stall the others.
 */
class RollForwardHolidaysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $toYear,
    ) {}

    public function handle(HolidayService $holidays, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $created = $holidays->rollForward($this->tenantId, $this->toYear);

        Log::info('RollForwardHolidays: complete', [
            'tenant_id' => $this->tenantId,
            'year' => $this->toYear,
            'holidays_created' => $created,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Safe to leave until next month: the run is idempotent and catches up.
        Log::error('RollForwardHolidaysJob failed', [
            'tenant_id' => $this->tenantId,
            'year' => $this->toYear,
            'error' => $exception->getMessage(),
        ]);
    }
}
