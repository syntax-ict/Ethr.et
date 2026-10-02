<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;

/**
 * What a shift or rotation can be assigned to: the `assignable_type` a request
 * names, and the model stored in `shift_assignments.assignable_type`.
 *
 * One map, read by every place that must agree on it: both assign actions
 * (which resolve the `public_id`), `ValidatesShiftAssignmentTenancy` (which
 * rejects a `public_id` that resolution would not find), the schedule filter,
 * and `ShiftAssignmentResource` (which reports the assignee in the request's
 * own vocabulary). Same reasoning as `EmployeeRelations`: a fourth type added
 * to the resolver and not the validator would be a 404 again, or worse.
 *
 * Every model here uses `BelongsToTenant`, so a plain query on it carries the
 * tenant predicate.
 */
final class ShiftAssignables
{
    public const MAP = [
        'employee' => Employee::class,
        'department' => Department::class,
        'branch' => Branch::class,
    ];

    /** @return class-string<Employee|Department|Branch>|null */
    public static function modelFor(mixed $type): ?string
    {
        return is_string($type) ? (self::MAP[$type] ?? null) : null;
    }

    /** The request-vocabulary name for a stored morph class, or null if it is not one of ours. */
    public static function typeFor(mixed $class): ?string
    {
        $type = array_search($class, self::MAP, true);

        return is_string($type) ? $type : null;
    }
}
