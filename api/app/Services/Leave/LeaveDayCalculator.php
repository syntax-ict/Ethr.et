<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

final class LeaveDayCalculator
{
    public function calculateDays(
        Carbon $startDate,
        Carbon $endDate,
        int $tenantId,
        ?int $branchId = null,
    ): float {
        $holidays = Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->format('Y-m-d') : (string) $d)
            ->toArray();

        $days = 0;
        $period = CarbonPeriod::create($startDate, $endDate);

        foreach ($period as $date) {
            if ($date->isWeekend()) {
                continue;
            }

            if (in_array($date->format('Y-m-d'), $holidays, true)) {
                continue;
            }

            $days++;
        }

        return (float) $days;
    }
}
