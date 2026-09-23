<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Team;

/**
 * The employee fields that arrive as a `public_id` and are stored as an integer
 * foreign key, and the model each resolves through.
 *
 * One map, read by two places that must not disagree:
 *
 *   - `EmployeeController::resolveRelationIds()` turns each `public_id` into an
 *     `id` with a tenant-scoped query, yielding `null` when it finds nothing;
 *   - `ValidatesRelationTenancy` rejects a value that same query would not find,
 *     so a request that validates is a request that resolves.
 *
 * They were separate literals until 2026-09-23. That is the shape root
 * `CLAUDE.md` warns about in `DispatchWebhookJob`, where two halves of one class
 * disagree about how much to state — and here it would be worse than untidy:
 * an eighth field added to the resolver but not to the validator would be a
 * silently nulled relation again, which is exactly the defect
 * `docs/audit/BASELINE.md` §11f was opened to close. Adding a field in one place
 * now adds it in both.
 */
final class EmployeeRelations
{
    /**
     * Field name in the request payload => the model its `public_id` belongs to.
     *
     * Every one of these models uses `BelongsToTenant`, so a plain query on it
     * carries the tenant predicate. Not every one soft-deletes — `CostCenter`
     * does not — which is why the validator asks the model rather than
     * assuming a `deleted_at` column exists.
     */
    public const MAP = [
        'department_id' => Department::class,
        'branch_id' => Branch::class,
        'position_id' => Position::class,
        'grade_id' => Grade::class,
        'team_id' => Team::class,
        'cost_center_id' => CostCenter::class,
        'supervisor_id' => Employee::class,
    ];
}
