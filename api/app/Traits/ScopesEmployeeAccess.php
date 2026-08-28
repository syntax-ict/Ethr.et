<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\OrgScope;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Provides org-hierarchy scoping for the User model.
 * DEPT_ADMIN sees only their department, SUPERVISOR sees only direct reports,
 * EMPLOYEE sees only self. Admins (TENANT_ADMIN, HR_ADMIN, FINANCE_ADMIN) see all.
 */
trait ScopesEmployeeAccess
{
    public function orgScope(): OrgScope
    {
        if ($this->custom_role_id) {
            $customRole = $this->customRole;
            if ($customRole && $customRole->org_scope) {
                return OrgScope::from($customRole->org_scope);
            }
        }

        return OrgScope::fromRole($this->role);
    }

    public function canAccessEmployee(Employee $employee): bool
    {
        return match ($this->orgScope()) {
            OrgScope::ALL => true,
            OrgScope::BRANCH => $this->employee_id
                && $this->employee?->branch_id === $employee->branch_id,
            OrgScope::DEPARTMENT => $this->employee_id
                && $this->employee?->department_id === $employee->department_id,
            OrgScope::TEAM => $this->employee_id
                && $this->employee?->team_id !== null
                && $this->employee?->team_id === $employee->team_id,
            OrgScope::DIRECT_REPORTS => $this->employee_id
                && ($employee->supervisor_id === $this->employee_id
                    || $employee->id === $this->employee_id),
            OrgScope::SELF => $this->employee_id
                && $employee->id === $this->employee_id,
        };
    }

    public function scopeAccessibleEmployees(Builder $query): Builder
    {
        return match ($this->orgScope()) {
            OrgScope::ALL => $query,
            OrgScope::BRANCH => $query->where(
                'branch_id',
                $this->employee?->branch_id,
            ),
            OrgScope::DEPARTMENT => $query->where(
                'department_id',
                $this->employee?->department_id,
            ),
            OrgScope::TEAM => $query->where(
                'team_id',
                $this->employee?->team_id,
            ),
            OrgScope::DIRECT_REPORTS => $query->where(function (Builder $q) {
                $q->where('supervisor_id', $this->employee_id)
                    ->orWhere('id', $this->employee_id);
            }),
            OrgScope::SELF => $query->where('id', $this->employee_id),
        };
    }

    public function accessibleEmployeeIds(): array
    {
        if ($this->orgScope() === OrgScope::ALL) {
            return [];
        }

        return $this->scopeAccessibleEmployees(Employee::query())
            ->pluck('id')
            ->all();
    }
}
