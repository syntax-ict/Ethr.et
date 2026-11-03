<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\OrgScope;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Provides org-hierarchy scoping for the User model.
 * DEPT_ADMIN sees their department and the units under it, SUPERVISOR sees only
 * direct reports, EMPLOYEE sees only self. Admins (TENANT_ADMIN, HR_ADMIN,
 * FINANCE_ADMIN) see all.
 *
 * A department reaches down, never across: a directorate head sees the
 * departments beneath the directorate and none beside it. Departments were
 * matched exactly, so the head of a government directorate whose staff all sit
 * in sub-units saw nobody. Team and branch stay exact: neither nests.
 */
trait ScopesEmployeeAccess
{
    /** @var array<int, list<int>> department id => it and every department under it */
    private array $departmentSubtrees = [];

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
            OrgScope::DEPARTMENT => $employee->department_id !== null
                && in_array((int) $employee->department_id, $this->departmentSubtree($anchor), true),
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
            OrgScope::DEPARTMENT => $query->whereIn('department_id', $this->departmentSubtree($anchor)),
            OrgScope::TEAM => $query->where('team_id', $anchor),
            OrgScope::DIRECT_REPORTS => $query->where(function (Builder $q) use ($anchor) {
                $q->where('supervisor_id', $anchor)
                    ->orWhere('id', $anchor);
            }),
            OrgScope::SELF => $query->where('id', $anchor),
        };
    }

    /**
     * The department and every department beneath it, walked one level at a
     * time. The tenant is stated on the query as well as by the global scope,
     * and an id already collected is never revisited, so a parent_id cycle
     * ends the walk instead of looping.
     *
     * @return list<int>
     */
    private function departmentSubtree(int $departmentId): array
    {
        if (isset($this->departmentSubtrees[$departmentId])) {
            return $this->departmentSubtrees[$departmentId];
        }

        $ids = [$departmentId];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $frontier = Department::query()
                ->where('tenant_id', $this->tenant_id)
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $frontier);
        }

        return $this->departmentSubtrees[$departmentId] = $ids;
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
