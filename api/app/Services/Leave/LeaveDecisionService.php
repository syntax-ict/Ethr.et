<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Services\Approval\ApprovalEngine;
use App\Services\Payroll\PayrollPeriodLock;
use App\Support\ApprovalRefusal;
use App\Traits\DispatchesWebhooks;
use App\Traits\SendsNotifications;

/**
 * Approving or rejecting a leave request — the one implementation behind both
 * `LeaveRequestController` and the batch approval centre.
 *
 * The batch path used to carry its own copy, and the copy had drifted: no team
 * check, no self-approval guard, a bare user id appended to `approved_by`
 * where the single path writes `{user_id, role, at}`, and no webhook. Callers
 * authorise through `LeaveRequestPolicy::approve` first; this class owns what
 * happens once that has passed.
 */
class LeaveDecisionService
{
    use DispatchesWebhooks, SendsNotifications;

    public function __construct(private readonly ApprovalEngine $engine) {}

    /** The state checks that follow authorisation, or null when the decision may go ahead. */
    public function refusal(LeaveRequest $leaveRequest, User $by, bool $approving): ?ApprovalRefusal
    {
        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            return ApprovalRefusal::notPending(__('leave.not_pending'));
        }

        if ($approving && $by->employee_id && $by->employee_id === $leaveRequest->employee_id) {
            return ApprovalRefusal::selfApproval(__('leave.cannot_approve_own'));
        }

        // Nor the person who filed it for someone else: HR may record an
        // unpaid or excused day on an employee's behalf, which payroll acts
        // on, so filing and approving are two people's acts, as for
        // corrections.
        if ($approving && $this->engine->filedForBy($leaveRequest) === $by->id) {
            return ApprovalRefusal::selfApproval(__('leave.cannot_approve_filed'));
        }

        // Unpaid leave is a deduction. Approved for days already paid in an
        // approved run, it changed no payslip and no later run picked it up,
        // so the employee kept pay the decision said to deduct (A-Z review
        // E-03). As for attendance, the run is voided and reprocessed first.
        // Paid leave recorded late moves no pay and stays open.
        if ($approving
            && $leaveRequest->leaveType?->is_paid === false
            && app(PayrollPeriodLock::class)->anyClosed($leaveRequest->employee_id, $leaveRequest->start_date, $leaveRequest->end_date)) {
            return ApprovalRefusal::payrollPeriodClosed(__('leave.payroll_period_closed'));
        }

        if (! $this->engine->isCurrentApprover($leaveRequest, $by, $approving)) {
            return ApprovalRefusal::notCurrentApprover(__('approval.not_current_approver'));
        }

        return null;
    }

    public function approve(LeaveRequest $leaveRequest, User $by): void
    {
        // A chain with steps still to go records this one and stays pending.
        if (! $this->engine->approve($leaveRequest, $by)) {
            return;
        }

        $chain = $leaveRequest->approved_by ?? [];
        $chain[] = [
            'user_id' => $by->id,
            'role' => $by->role->value,
            'at' => now()->toIso8601String(),
        ];

        $leaveRequest->update([
            'approved_by' => $chain,
            'status' => LeaveStatus::APPROVED,
        ]);

        $balance = $this->balanceOf($leaveRequest);

        if ($balance) {
            $balance->decrement('pending_days', (float) $leaveRequest->days);
            $balance->increment('used_days', (float) $leaveRequest->days);
        }

        AuditLog::record('leave.approved', $leaveRequest, [
            'approved_by' => $by->id,
        ]);
        $this->webhook($leaveRequest->employee->tenant_id, 'leave.approved', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee->name,
        ]);

        $this->notify(
            $this->userOf($leaveRequest->employee),
            new LeaveApprovedNotification($leaveRequest),
        );
    }

    public function reject(LeaveRequest $leaveRequest, User $by, string $reason): void
    {
        $this->engine->reject($leaveRequest, $by, $reason);

        $leaveRequest->update([
            'status' => LeaveStatus::REJECTED,
            'rejected_by' => $by->id,
            'rejected_reason' => $reason,
        ]);

        $balance = $this->balanceOf($leaveRequest);

        if ($balance) {
            $balance->decrement('pending_days', (float) $leaveRequest->days);
        }

        AuditLog::record('leave.rejected', $leaveRequest, [
            'rejected_by' => $by->id,
            'reason' => $reason,
        ]);
        $this->webhook($leaveRequest->employee->tenant_id, 'leave.rejected', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee->name,
            'reason' => $reason,
        ]);

        $this->notify(
            $this->userOf($leaveRequest->employee),
            new LeaveRejectedNotification($leaveRequest),
        );
    }

    private function balanceOf(LeaveRequest $leaveRequest): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', LeaveYear::forTenantId($leaveRequest->tenant_id)->yearOf($leaveRequest->start_date))
            ->first();
    }
}
