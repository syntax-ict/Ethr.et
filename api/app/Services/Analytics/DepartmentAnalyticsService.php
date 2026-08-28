<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;

final class DepartmentAnalyticsService
{
    public function compare(int $tenantId, Carbon $from, Carbon $to): array
    {
        $departments = Department::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->get();

        return $departments->map(function ($dept) use ($tenantId, $from, $to) {
            $activeStatuses = [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED];

            $headcount = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('department_id', $dept->id)
                ->whereIn('status', $activeStatuses)
                ->count();

            $attendanceCount = AttendanceRecord::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->whereHas('employee', fn ($q) => $q->where('department_id', $dept->id))
                ->count();

            $workingDays = max(1, $from->diffInWeekdays($to));
            $expectedAttendance = $headcount * $workingDays;
            $attendanceRate = $expectedAttendance > 0
                ? round(($attendanceCount / $expectedAttendance) * 100, 1)
                : 0;

            // Query-builder aggregates bypass the model's casts and come back
            // from PDO as a numeric string, which round() rejects outright
            // under strict_types. Collection::avg() (see detail()) is cast and
            // needs no such help.
            $avgSalary = (float) (Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('department_id', $dept->id)
                ->whereIn('status', $activeStatuses)
                ->avg('salary_cents') ?? 0);

            return [
                'public_id' => $dept->public_id,
                'name' => $dept->name,
                'headcount' => $headcount,
                'attendance_rate' => $attendanceRate,
                'avg_salary_cents' => (int) round($avgSalary),
            ];
        })->toArray();
    }

    public function detail(int $tenantId, Department $department, Carbon $from, Carbon $to): array
    {
        $activeStatuses = [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED];

        $employees = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('department_id', $department->id)
            ->whereIn('status', $activeStatuses)
            ->get();

        $headcount = $employees->count();
        $avgSalary = (int) round($employees->avg('salary_cents') ?? 0);

        $genderBreakdown = $employees->groupBy('gender')->map->count()->toArray();

        return [
            'public_id' => $department->public_id,
            'name' => $department->name,
            'headcount' => $headcount,
            'avg_salary_cents' => $avgSalary,
            'gender_breakdown' => $genderBreakdown,
            'employees' => $employees->map(fn ($e) => [
                'public_id' => $e->public_id,
                'name' => $e->name,
                'status' => $e->status->value,
            ])->toArray(),
        ];
    }
}
