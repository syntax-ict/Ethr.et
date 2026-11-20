<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayDifferenceRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Works out the pay differences a change flagged for one employee. Safe to run
 * twice: it only touches rows still pending, and a second run finds none.
 */
class ComputePayDifferencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $employeeId,
    ) {
        $this->onQueue('default');
    }

    public function handle(PayDifferenceRecorder $recorder, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $currentTenant->set($tenant);

        $recorder->computePending($this->tenantId, [$this->employeeId]);
    }
}
