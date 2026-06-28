<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;

final class BranchAnalyticsService
{
    public function compare(int $tenantId, Carbon $from, Carbon $to): array
    {
        $branches = Branch::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->get();

        $activeStatuses = [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED];

        return $branches->map(function ($branch) use ($tenantId, $activeStatuses) {
            $headcount = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branch->id)
                ->whereIn('status', $activeStatuses)
                ->count();

            $departmentCount = Department::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branch->id)
                ->count();

            return [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'headcount' => $headcount,
                'department_count' => $departmentCount,
            ];
        })->toArray();
    }

    public function detail(int $tenantId, Branch $branch): array
    {
        $activeStatuses = [EmployeeStatus::HIRED, EmployeeStatus::PROBATION, EmployeeStatus::CONFIRMED];

        $headcount = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branch->id)
            ->whereIn('status', $activeStatuses)
            ->count();

        $departments = Department::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branch->id)
            ->get()
            ->map(fn ($d) => [
                'public_id' => $d->public_id,
                'name' => $d->name,
            ]);

        return [
            'public_id' => $branch->public_id,
            'name' => $branch->name,
            'headcount' => $headcount,
            'departments' => $departments,
        ];
    }
}
