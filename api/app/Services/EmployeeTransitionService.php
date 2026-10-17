<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Events\EmployeeTransitioned;
use App\Exceptions\InvalidEmployeeTransition;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An employee's status change: the status machine's check, the
 * `EmployeeTransition` row, the confirmation or termination date, the audit
 * entry and the `EmployeeTransitioned` event, in that order and together.
 *
 * The single transition endpoint and bulk update both come through here. Bulk
 * update used to write `status` with one `update()`, which skipped every one
 * of those steps (capability audit 2026-10-17).
 */
final class EmployeeTransitionService
{
    private const LEAVING = [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED];

    /**
     * @throws InvalidEmployeeTransition when the status machine does not allow it
     */
    public function transition(
        Employee $employee,
        EmployeeStatus $to,
        string $effectiveDate,
        ?string $reason,
        ?int $approvedBy,
    ): EmployeeTransition {
        if (! $employee->status->canTransitionTo($to)) {
            throw new InvalidEmployeeTransition($employee->status, $to);
        }

        $transition = DB::transaction(function () use ($employee, $to, $effectiveDate, $reason, $approvedBy): EmployeeTransition {
            $transition = EmployeeTransition::create([
                'employee_id' => $employee->id,
                'from_status' => $employee->status->value,
                'to_status' => $to->value,
                'reason' => $reason,
                'effective_date' => $effectiveDate,
                'approved_by' => $approvedBy,
            ]);

            $employee->status = $to;

            if ($to === EmployeeStatus::CONFIRMED) {
                $employee->confirmation_date = Carbon::parse($effectiveDate);
            }

            if (in_array($to, self::LEAVING, true)) {
                $employee->termination_date = Carbon::parse($effectiveDate);
            }

            $employee->save();

            AuditLog::record('employee.transitioned', $employee, [
                'from' => $transition->from_status->value,
                'to' => $transition->to_status->value,
                'reason' => $transition->reason,
            ]);

            return $transition;
        });

        EmployeeTransitioned::dispatch($employee, $transition);

        return $transition;
    }

    /**
     * The effective date a caller that sends none gets: today in the
     * organisation's own zone, since the app clock is UTC and for the first
     * three hours of an Addis Ababa day UTC is still on yesterday.
     */
    public static function today(): string
    {
        return CarbonImmutable::now(TenantTime::zone(app(CurrentTenant::class)->get()))->toDateString();
    }
}
