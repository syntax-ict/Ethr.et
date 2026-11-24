<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
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
     * @throws HttpException 403 when the plan has no seat for them
     */
    public function restore(Employee $employee): bool
    {
        if (! $employee->trashed()) {
            return false;
        }

        $tenant = app(CurrentTenant::class)->get();
        $seats = $this->planLimits->lock($tenant, 'employees');
        try {
            $this->planLimits->assertCanAdd($tenant, 'employees');
            $employee->restore();
        } finally {
            $seats->release();
        }

        AuditLog::record('employee.restored', $employee);
        $this->webhook($employee->tenant_id, 'employee.updated', ['public_id' => $employee->public_id, 'name' => $employee->name]);

        return true;
    }
}
