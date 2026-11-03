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
use App\Support\ApprovalRefusal;
use App\Traits\DispatchesWebhooks;
use App\Traits\SendsNotifications;
use Illuminate\Support\Facades\DB;

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

        if (! $this->engine->isCurrentApprover($leaveRequest, $by, $approving)) {
            return ApprovalRefusal::notCurrentApprover(__('approval.not_current_approver'));
        }

        return null;
    }

    public function approve(LeaveRequest $leaveRequest, User $by): void
    {
        // Decided once. The request is read again under a lock, so two
        // approvers in the same second (or an approval racing the employee's
        // cancel) cannot both pass the pending check: the days were taken
        // from the balance twice (A-Z review E-01).
        $decided = DB::transaction(function () use ($leaveRequest, $by): bool {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== LeaveStatus::PENDING) {
                return false;
            }

            // A chain with steps still to go records this one and stays pending.
            if (! $this->engine->approve($locked, $by)) {
                return false;
            }

            $chain = $locked->approved_by ?? [];
            $chain[] = [
                'user_id' => $by->id,
                'role' => $by->role->value,
                'at' => now()->toIso8601String(),
            ];

            $locked->update([
                'approved_by' => $chain,
                'status' => LeaveStatus::APPROVED,
            ]);

            $balance = $this->balanceOf($locked);

            if ($balance) {
                $balance->decrement('pending_days', (float) $locked->days);
                $balance->increment('used_days', (float) $locked->days);
            }

            return true;
        });

        $leaveRequest->refresh();

        if (! $decided) {
            return;
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
        $decided = DB::transaction(function () use ($leaveRequest, $by, $reason): bool {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== LeaveStatus::PENDING) {
                return false;
            }

            $this->engine->reject($locked, $by, $reason);

            $locked->update([
                'status' => LeaveStatus::REJECTED,
                'rejected_by' => $by->id,
                'rejected_reason' => $reason,
            ]);

            $balance = $this->balanceOf($locked);

            if ($balance) {
                $balance->decrement('pending_days', (float) $locked->days);
            }

            return true;
        });

        $leaveRequest->refresh();

        if (! $decided) {
            return;
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
