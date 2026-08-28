<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\AccrualType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;

final class LeaveBalanceService
{
    public function getOrCreateBalance(Employee $employee, LeaveType $leaveType, int $year): LeaveBalance
    {
        $initialEntitlement = $leaveType->accrual_type === AccrualType::MONTHLY
            ? 0
            : $leaveType->default_days;

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

    public function calculateBalance(Employee $employee, LeaveType $leaveType, int $year): float
    {
        $balance = $this->getOrCreateBalance($employee, $leaveType, $year);

        return $balance->remainingDays();
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

        foreach ($leaveTypes as $leaveType) {
            $targetEntitled = round((float) $leaveType->default_days * $currentMonth / 12, 1);

            $employees = Employee::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->get();

            foreach ($employees as $employee) {
                if (! $leaveType->isAvailableForGender($employee->gender)) {
                    continue;
                }

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
