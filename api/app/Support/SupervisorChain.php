<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;

/**
 * Whether one person may be put under another in the reporting tree.
 *
 * One rule, read by the edit form's validation (`ValidatesRelationTenancy`) and
 * by the bulk change per person, so the two cannot disagree: nobody supervises
 * themselves, and nobody supervises someone who, further up, supervises them
 * (A-Z review C-08). The chain is walked from the new supervisor upwards, bounded,
 * because a chain already broken must not hang the request. Every lookup is the
 * tenant-scoped model's own.
 */
final class SupervisorChain
{
    /** The language key of the refusal, or null when the assignment is allowed. */
    public static function refusal(Employee $employee, string $supervisorPublicId): ?string
    {
        if ($supervisorPublicId === $employee->public_id) {
            return 'employee.supervisor_self';
        }

        $next = Employee::query()->where('public_id', $supervisorPublicId)->value('supervisor_id');
        for ($depth = 0; $next !== null && $depth < 100; $depth++) {
            if ((int) $next === $employee->id) {
                return 'employee.supervisor_loop';
            }
            $next = Employee::query()->whereKey($next)->value('supervisor_id');
        }

        return null;
    }
}
