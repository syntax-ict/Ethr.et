<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The narrowing the employees list applies to its query, written once.
 *
 * `GET /employees` and `GET /employees/ids` (the people a "select everyone
 * matching this filter" would tick) both read it, so the set the screen counts
 * and the set the ids endpoint names cannot differ. The org scope is not here:
 * each caller applies `scopeAccessibleEmployees()` itself, before this.
 */
final class EmployeeListFilters
{
    /**
     * `$values` is what the request carries for `search`, `status`, `missing`,
     * `department_id`, `branch_id` and `position_id`, read by the caller from
     * the request in its own body. The caller names them because the published
     * contract is derived from the controller method's own reads of the
     * request, and a read made in here would take these parameters out of it.
     * Whether a parameter was sent at all is asked of the request.
     *
     * @param  Builder<Employee>  $query
     * @param  array{search: mixed, status: mixed, missing: mixed, department_id: mixed, branch_id: mixed, position_id: mixed}  $values
     */
    public static function apply(Builder $query, Request $request, array $values): void
    {
        if ($request->filled('search')) {
            $query->search($values['search']);
        }

        // The archived people, and only them. Seeing who was archived is the
        // permission to archive and restore them, not the permission to list.
        if ($request->boolean('filter.archived')) {
            Gate::authorize('employee.delete');
            $query->onlyTrashed();
        }

        if ($request->has('filter.status')) {
            $query->where('status', $values['status']);
        }

        // The "check nationality" list: employees the 2026-10-14 backfill
        // mapped to foreign from a free-text value nobody has confirmed.
        if ($request->boolean('filter.nationality_needs_review')) {
            $query->where('nationality_needs_review', true);
        }

        // The "records to fix" list a Next Steps row opens. It is the set the
        // row counted, through EmployeeGaps.
        // Pay data needs the permission that edits pay data, so the list
        // cannot be used to learn who has no TIN or bank account.
        EmployeeGaps::restrict($query, $values['missing']);

        if ($request->has('filter.department_id')) {
            $dept = Department::where('public_id', $values['department_id'])->first();
            if ($dept) {
                $query->where('department_id', $dept->id);
            }
        }

        if ($request->has('filter.branch_id')) {
            $branch = Branch::where('public_id', $values['branch_id'])->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        if ($request->has('filter.position_id')) {
            $position = Position::where('public_id', $values['position_id'])->first();
            if ($position) {
                $query->where('position_id', $position->id);
            }
        }
    }
}
