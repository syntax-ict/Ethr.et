<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\PlanLimitService;
use App\Traits\DispatchesWebhooks;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bring an archived employee back: the one place that does it, for the single
 * restore endpoint and the bulk one.
 *
 * The person takes a seat again, so the plan's employee limit applies, counted
 * under the seat lock as a new employee is. A restore does not reopen a login
 * that archiving closed.
 */
final class EmployeeRestorer
{
    use DispatchesWebhooks;

    public function __construct(private readonly PlanLimitService $planLimits) {}

    /**
     * Restore the employee when they are archived. Returns whether anything
     * changed: a person who is not archived is left alone.
     *
     * A caller already inside the organisation's seat lock (a list being added
     * holds it for the chunk) says so with `$seatHeld`: the lock is not taken
     * again, since it is not re-entrant, and the plan's limit is still asked.
     * `$audit` is recorded with the `employee.restored` entry.
     *
     * @param  array<string, mixed>  $audit
     *
     * @throws HttpException 403 when the plan has no seat for them
     */
    public function restore(Employee $employee, bool $seatHeld = false, array $audit = [], ?Tenant $tenant = null): bool
    {
        if (! $employee->trashed()) {
            return false;
        }

        $tenant ??= app(CurrentTenant::class)->get();

        if ($seatHeld) {
            $this->planLimits->assertCanAdd($tenant, 'employees');
            $employee->restore();
        } else {
            $seats = $this->planLimits->lock($tenant, 'employees');
            try {
                $this->planLimits->assertCanAdd($tenant, 'employees');
                $employee->restore();
            } finally {
                $seats->release();
            }
        }

        AuditLog::record('employee.restored', $employee, $audit);
        $this->webhook($employee->tenant_id, 'employee.updated', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        return true;
    }
}
