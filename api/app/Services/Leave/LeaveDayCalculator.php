<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Services\Attendance\ShiftMatcher;
use App\Services\CurrentTenant;
use App\Support\WorkingWeek;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

final class LeaveDayCalculator
{
    /**
     * The leave days between two dates: working days that are not holidays.
     * With `$employee`, a working day is one that person is scheduled to
     * work (their rotation, else their shift, else the organisation's week:
     * ShiftMatcher::workingDays, the rules attendance uses). Without one, the
     * organisation's week. Counting on the organisation's week alone charged
     * a four-day shift for its rest day and never charged a Saturday shift
     * for Saturdays (A-Z review E-06).
     */
    public function calculateDays(
        Carbon $startDate,
        Carbon $endDate,
        int $tenantId,
        ?int $branchId = null,
        ?Employee $employee = null,
    ): float {
        return (float) count($this->chargedDates($startDate, $endDate, $tenantId, $branchId, $employee));
    }

    /**
     * The dates a leave from $startDate to $endDate charges, as calculateDays()
     * counts them: the person's working days, holidays excluded. Sick leave
     * under Art. 85 needs the dates themselves, to know which day of the
     * twelve-month period each one is (`SickLeaveTiers`).
     *
     * @return list<string> Y-m-d, in order
     */
    public function chargedDates(
        Carbon $startDate,
        Carbon $endDate,
        int $tenantId,
        ?int $branchId = null,
        ?Employee $employee = null,
    ): array {
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

        // `Tenant` is global (no tenant scope), so this needs no bypass.
        $workingDays = WorkingWeek::of(app(CurrentTenant::class)->tenantFor($tenantId));
        $scheduled = $employee === null
            ? null
            : app(ShiftMatcher::class)->workingDays($employee, $startDate, $endDate, $workingDays);

        $dates = [];
        $period = CarbonPeriod::create($startDate, $endDate);

        foreach ($period as $date) {
            $works = $scheduled === null
                ? in_array($date->dayOfWeekIso, $workingDays, true)
                : ($scheduled[$date->format('Y-m-d')] ?? false);
            if (! $works) {
                continue;
            }

            if (in_array($date->format('Y-m-d'), $holidays, true)) {
                continue;
            }

            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }
}
