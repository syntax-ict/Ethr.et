<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

/**
 * A person who has left the organisation stops being able to sign in on their
 * leaving date, not before it and not never.
 *
 * Until 2026-10-20 nothing connected the two: moving an employee to resigned,
 * terminated or retired left their login active, with every token it had
 * issued, until someone remembered to deactivate the user by hand (blueprint
 * assessment, offboarding). A leaving date in the future is a notice period,
 * so access is closed when the date arrives: EmployeeTransitioned closes it
 * at once when the date has already come, and RejectInactiveUser closes it on
 * the first request after a future date passes.
 */
final class LeaverAccess
{
    private const LEAVING = [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED];

    public static function hasLeft(?Employee $employee): bool
    {
        return $employee !== null
            && in_array($employee->status, self::LEAVING, true)
            && $employee->termination_date !== null
            && $employee->termination_date->lte(Carbon::today());
    }

    /** Deactivate `$user` and end every session and token it holds. */
    public static function close(User $user): void
    {
        if ($user->status !== 'active') {
            return;
        }

        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        AuditLog::record('user.deactivated_on_exit', $user, [
            'employee_id' => $user->employee?->public_id,
            'termination_date' => $user->employee?->termination_date?->toDateString(),
        ]);
    }
}
