<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmployeeTransitioned;
use App\Models\User;
use App\Support\LeaverAccess;

/**
 * Not queued: the transition is recorded inside a request whose tenant is
 * resolved, and the login should be closed before that request returns.
 */
final class CloseLeaverLogin
{
    public function handle(EmployeeTransitioned $event): void
    {
        $employee = $event->employee->fresh() ?? $event->employee;

        if (! LeaverAccess::hasLeft($employee)) {
            return;
        }

        User::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->get()
            ->each(fn (User $user) => LeaverAccess::close($user));
    }
}
