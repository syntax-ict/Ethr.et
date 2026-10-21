<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\AccrualType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\Carbon;

final class LeaveBalanceService
{
    /**
     * The year's full entitlement for `$employee`: the type's days plus its
     * seniority step for every `seniority_every_years` of service completed by
     * `$asOf`. Labour Proclamation 1156/2019 Art. 77(1): sixteen days in the
     * first year, one more for every additional two years.
     */
    public function yearlyEntitlement(Employee $employee, LeaveType $leaveType, Carbon $asOf): float
    {
        $days = (float) $leaveType->default_days;
        $every = (int) $leaveType->seniority_every_years;
        $extra = (float) $leaveType->seniority_extra_days;

        if ($every > 0 && $extra > 0 && $employee->hire_date !== null && $employee->hire_date->lt($asOf)) {
            $completedYears = (int) $employee->hire_date->diffInYears($asOf);
            $days += intdiv($completedYears, $every) * $extra;
        }

        return $days;
    }

    /**
     * Months of `$year`, up to and including `$throughMonth`, in which
     * `$employee` was employed: from the hire month (or January) to the
     * leaving month (or `$throughMonth`). A part month counts as a month.
     */
    public function monthsEmployed(Employee $employee, int $year, int $throughMonth): int
    {
        $first = 1;
        if ($employee->hire_date !== null) {
            if ($employee->hire_date->year > $year) {
                return 0;
            }
            if ($employee->hire_date->year === $year) {
                $first = $employee->hire_date->month;
            }
        }

        $last = $throughMonth;
        if ($employee->termination_date !== null) {
            if ($employee->termination_date->year < $year) {
                return 0;
            }
            if ($employee->termination_date->year === $year) {
                $last = min($last, $employee->termination_date->month);
            }
        }

        return max(0, $last - $first + 1);
    }

    public function getOrCreateBalance(Employee $employee, LeaveType $leaveType, int $year): LeaveBalance
    {
        $initialEntitlement = $leaveType->accrual_type === AccrualType::MONTHLY
            ? 0
            : $this->yearlyEntitlement($employee, $leaveType, Carbon::create($year, 1, 1));

        return LeaveBalance::firstOrCreate(
            [
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
            ],
            [
                'entitled_days' => $initialEntitlement,
                'used_days' => 0,
                'carried_days' => 0,
                'pending_days' => 0,
            ]
        );
    }

    public function accrueMonthly(int $tenantId): int
    {
        // Explicitly scoped for the same reason as carryForward() — the tenant
        // comes from the signature, not from ambient request state.
        $leaveTypes = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('accrual_type', AccrualType::MONTHLY)
            ->where('is_active', true)
            ->get();

        $accrued = 0;
        $year = now()->year;
        $currentMonth = now()->month;

        // Accrual is for the months someone was employed this year, at the
        // entitlement their service has reached. Until 2026-10-20 every
        // employee on the books accrued from January at the base rate: a
        // March hire was credited January and February, a leaver went on
        // accruing for good, and a ten-year employee accrued what a new one
        // did (blueprint assessment LV2, LV6).
        $asOf = Carbon::create($year, $currentMonth, 1)->endOfMonth();

        foreach ($leaveTypes as $leaveType) {
            $employees = Employee::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->get();

            foreach ($employees as $employee) {
                if (! $leaveType->isAvailableForGender($employee->gender)) {
                    continue;
                }

                $months = $this->monthsEmployed($employee, $year, $currentMonth);
                if ($months === 0) {
                    continue;
                }

                $targetEntitled = round($this->yearlyEntitlement($employee, $leaveType, $asOf) * $months / 12, 1);

                $balance = $this->getOrCreateBalance($employee, $leaveType, $year);
                $increment = round($targetEntitled - (float) $balance->entitled_days, 1);

                if ($increment > 0) {
                    $balance->increment('entitled_days', $increment);
                    $accrued++;
                }
            }
        }

        return $accrued;
    }

    public function carryForward(int $tenantId, int $fromYear, int $toYear): int
    {
        // Scoped explicitly rather than via the global scope: this runs from a
        // queued job, where the ambient tenant is whatever the job set — and
        // the caller names the tenant in the signature.
        $leaveTypes = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('carry_forward', true)
            ->where('is_active', true)
            ->get();

        $carried = 0;

        foreach ($leaveTypes as $leaveType) {
            $balances = LeaveBalance::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $fromYear)
                ->get();

            foreach ($balances as $balance) {
                $remaining = $balance->remainingDays();

                if ($remaining <= 0) {
                    continue;
                }

                $employee = Employee::withoutGlobalScope('tenant')
                    ->where('id', $balance->employee_id)
                    ->first();

                // Employee deleted since the balance was written — nothing to
                // carry into, and getOrCreateBalance() requires an Employee.
                if (! $employee) {
                    continue;
                }

                $carryDays = $leaveType->max_carry_days !== null
                    ? min($remaining, (float) $leaveType->max_carry_days)
                    : $remaining;

                $newBalance = $this->getOrCreateBalance($employee, $leaveType, $toYear);
                $newBalance->update(['carried_days' => $carryDays]);

                $carried++;
            }
        }

        return $carried;
    }

    public function adjustBalance(Employee $employee, LeaveType $leaveType, float $adjustment, int $year): LeaveBalance
    {
        $balance = $this->getOrCreateBalance($employee, $leaveType, $year);
        $balance->increment('entitled_days', $adjustment);

        return $balance->fresh();
    }
}
