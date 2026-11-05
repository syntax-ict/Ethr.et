<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveChangedNotification;
use App\Traits\DispatchesWebhooks;
use App\Traits\SendsNotifications;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR cancelling approved leave, or ending it early (A-Z review E-02).
 *
 * Approval moved the days from pending to used, and nothing moved them back:
 * someone who came back early, or whose trip was called off, kept the leave
 * on their record and lost the days from their balance. The days the request
 * no longer takes go back to the balance of the year it was charged to. The
 * controller has already refused what may not change (own leave, a closed
 * payroll period, an end date outside the leave); this applies the change
 * once, under a lock.
 */
final class LeaveRecallService
{
    use DispatchesWebhooks, SendsNotifications;

    public function __construct(private readonly LeaveDayCalculator $calculator) {}

    /** The days the request would count if it ended on $end, as `store` counts them. */
    public function daysUntil(LeaveRequest $leaveRequest, Carbon $end): float
    {
        $start = $leaveRequest->start_date->copy()->startOfDay();

        if ($leaveRequest->leaveType?->counts_calendar_days) {
            return (float) ((int) $start->diffInDays($end) + 1);
        }

        $employee = $leaveRequest->employee;

        return $this->calculator->calculateDays($start, $end, $leaveRequest->tenant_id, $employee?->branch_id, $employee);
    }

    /**
     * Cancels the request when $end is null, otherwise ends it on $end.
     * False when it was no longer approved, or had changed, by the time the
     * lock was taken.
     */
    public function recall(LeaveRequest $leaveRequest, User $by, ?Carbon $end, string $reason): bool
    {
        $before = [
            'end_date' => $leaveRequest->end_date->format('Y-m-d'),
            'days' => (float) $leaveRequest->days,
        ];
        $keptDays = $end === null ? 0.0 : $this->daysUntil($leaveRequest, $end);

        $changed = DB::transaction(function () use ($leaveRequest, $end, $keptDays, $before): bool {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->getKey())->lockForUpdate()->first();
            if ($locked === null
                || $locked->status !== LeaveStatus::APPROVED
                || $locked->end_date->format('Y-m-d') !== $before['end_date']) {
                return false;
            }

            $locked->update($end === null
                ? ['status' => LeaveStatus::CANCELLED]
                : ['end_date' => $end->format('Y-m-d'), 'days' => $keptDays]);

            $freed = $before['days'] - $keptDays;
            $balance = LeaveBalance::query()
                ->where('employee_id', $locked->employee_id)
                ->where('leave_type_id', $locked->leave_type_id)
                ->where('year', LeaveYear::forTenantId($locked->tenant_id)->yearOf($locked->start_date))
                ->lockForUpdate()
                ->first();

            if ($balance !== null && $freed > 0) {
                $balance->update(['used_days' => max(0.0, (float) $balance->used_days - $freed)]);
            }

            return true;
        });

        $leaveRequest->refresh();

        if (! $changed) {
            return false;
        }

        AuditLog::record('leave.recalled', $leaveRequest, [
            'by' => $by->id,
            'reason' => $reason,
            'cancelled' => $end === null,
            'end_date_before' => $before['end_date'],
            'end_date_after' => $leaveRequest->end_date->format('Y-m-d'),
            'days_before' => $before['days'],
            'days_after' => $keptDays,
        ]);

        $this->webhook($leaveRequest->tenant_id, 'leave.changed', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee?->name,
            'status' => $leaveRequest->status->value,
            'end_date' => $leaveRequest->end_date->format('Y-m-d'),
            'days' => $keptDays,
            'reason' => $reason,
        ]);

        $this->notify(
            $this->userOf($leaveRequest->employee),
            new LeaveChangedNotification($leaveRequest, $keptDays, $reason),
        );

        return true;
    }
}
