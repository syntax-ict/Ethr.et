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
        return LeaveBalance::firstOrCreate(
            [
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
            ],
            [
                'entitled_days' => $leaveType->default_days,
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
        $leaveTypes = LeaveType::query()
            ->where('accrual_type', AccrualType::MONTHLY)
            ->where('is_active', true)
            ->get();

        $accrued = 0;
        $year = now()->year;

        foreach ($leaveTypes as $leaveType) {
            $monthlyAccrual = round((float) $leaveType->default_days / 12, 1);

            $employees = Employee::query()
                ->where('tenant_id', $tenantId)
                ->get();

            foreach ($employees as $employee) {
                if (! $leaveType->isAvailableForGender($employee->gender)) {
                    continue;
                }

                $balance = $this->getOrCreateBalance($employee, $leaveType, $year);
                $balance->increment('entitled_days', $monthlyAccrual);
                $accrued++;
            }
        }

        return $accrued;
    }

    public function carryForward(int $tenantId, int $fromYear, int $toYear): int
    {
        $leaveTypes = LeaveType::query()
            ->where('carry_forward', true)
            ->where('is_active', true)
            ->get();

        $carried = 0;

        foreach ($leaveTypes as $leaveType) {
            $balances = LeaveBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $fromYear)
                ->get();

            foreach ($balances as $balance) {
                $remaining = $balance->remainingDays();

                if ($remaining <= 0) {
                    continue;
                }

                $carryDays = $leaveType->max_carry_days !== null
                    ? min($remaining, (float) $leaveType->max_carry_days)
                    : $remaining;

                $newBalance = $this->getOrCreateBalance(
                    Employee::find($balance->employee_id),
                    $leaveType,
                    $toYear
                );
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
