<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\Carbon;

final class ShiftMatcher
{
    public function match(Employee $employee, Carbon $date): ?Shift
    {
        $assignment = $this->findEmployeeAssignment($employee, $date)
            ?? $this->findDepartmentAssignment($employee, $date)
            ?? $this->findBranchAssignment($employee, $date);

        if ($assignment) {
            return $assignment->shift;
        }

        return $this->findDefaultShift($employee);
    }

    private function findEmployeeAssignment(Employee $employee, Carbon $date): ?ShiftAssignment
    {
        return $this->buildAssignmentQuery(Employee::class, $employee->id, $date);
    }

    private function findDepartmentAssignment(Employee $employee, Carbon $date): ?ShiftAssignment
    {
        if (! $employee->department_id) {
            return null;
        }

        return $this->buildAssignmentQuery(Department::class, $employee->department_id, $date);
    }

    private function findBranchAssignment(Employee $employee, Carbon $date): ?ShiftAssignment
    {
        if (! $employee->branch_id) {
            return null;
        }

        return $this->buildAssignmentQuery(Branch::class, $employee->branch_id, $date);
    }

    private function buildAssignmentQuery(string $type, int $id, Carbon $date): ?ShiftAssignment
    {
        $dateStr = $date->format('Y-m-d');

        return ShiftAssignment::query()
            ->where('assignable_type', $type)
            ->where('assignable_id', $id)
            ->whereDate('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $dateStr);
            })
            ->whereHas('shift', fn ($q) => $q->where('is_active', true))
            ->with('shift')
            ->latest('effective_from')
            ->first();
    }

    private function findDefaultShift(Employee $employee): ?Shift
    {
        return Shift::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function calculateStatus(Carbon $checkIn, Shift $shift): string
    {
        $shiftStart = $checkIn->copy()->setTimeFromTimeString($shift->start_time);

        $graceEnd = $shiftStart->copy()->addMinutes($shift->grace_minutes);

        if ($checkIn->lte($graceEnd)) {
            return 'present';
        }

        return 'late';
    }
}
