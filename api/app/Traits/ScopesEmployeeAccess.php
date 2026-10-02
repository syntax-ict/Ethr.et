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
        $scope = $this->orgScope();

        if ($scope === OrgScope::ALL) {
            return true;
        }

        $anchor = $this->orgScopeAnchor($scope);

        if ($anchor === null) {
            return false;
        }

        return match ($scope) {
            OrgScope::BRANCH => $employee->branch_id === $anchor,
            OrgScope::DEPARTMENT => $employee->department_id === $anchor,
            OrgScope::TEAM => $employee->team_id === $anchor,
            OrgScope::DIRECT_REPORTS => $employee->supervisor_id === $anchor
                || $employee->id === $anchor,
            OrgScope::SELF => $employee->id === $anchor,
        };
    }

    /**
     * The query counterpart of canAccessEmployee(), and it must fail closed the
     * same way.
     *
     * `where('supervisor_id', null)` is not "matches nothing": Laravel turns a
     * null value into `IS NULL`. So a supervisor-role login with no employee
     * record was scoped to every employee who has no supervisor, a branch- or
     * department-scoped login with no branch or department to every employee
     * with none, and a team-scoped employee outside any team to every teamless
     * employee. canAccessEmployee() refused the supervisor and team cases one
     * row at a time but matched null to null for branch and department. Both
     * now read the same anchor, and a missing anchor reaches no one.
     */
    public function scopeAccessibleEmployees(Builder $query): Builder
    {
        $scope = $this->orgScope();

        if ($scope === OrgScope::ALL) {
            return $query;
        }

        $anchor = $this->orgScopeAnchor($scope);

        if ($anchor === null) {
            return $query->whereRaw('1 = 0');
        }

        return match ($scope) {
            OrgScope::BRANCH => $query->where('branch_id', $anchor),
            OrgScope::DEPARTMENT => $query->where('department_id', $anchor),
            OrgScope::TEAM => $query->where('team_id', $anchor),
            OrgScope::DIRECT_REPORTS => $query->where(function (Builder $q) use ($anchor) {
                $q->where('supervisor_id', $anchor)
                    ->orWhere('id', $anchor);
            }),
            OrgScope::SELF => $query->where('id', $anchor),
        };
    }

    /**
     * The value a non-ALL scope is measured from: the caller's branch,
     * department or team, or their own employee id. Null when the caller has
     * no employee record or that employee has no such unit.
     */
    private function orgScopeAnchor(OrgScope $scope): ?int
    {
        if (! $this->employee_id) {
            return null;
        }

        return match ($scope) {
            OrgScope::ALL => null,
            OrgScope::BRANCH => $this->employee?->branch_id,
            OrgScope::DEPARTMENT => $this->employee?->department_id,
            OrgScope::TEAM => $this->employee?->team_id,
            OrgScope::DIRECT_REPORTS, OrgScope::SELF => $this->employee_id,
        };
    }
}
