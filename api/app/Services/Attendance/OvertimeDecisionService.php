<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\OvertimeRequestStatus;
use App\Models\AuditLog;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\OvertimeDecidedNotification;
use App\Services\Approval\ApprovalEngine;
use App\Support\ApprovalRefusal;
use App\Traits\SendsNotifications;

/**
 * Approving or rejecting an overtime request: the one implementation behind
 * its own endpoints and the batch approval centre. Callers authorise through
 * `OvertimeRequestPolicy::decide` first.
 */
class OvertimeDecisionService
{
    use SendsNotifications;

    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * The state checks that follow authorisation, or null when the decision
     * may go ahead. Nobody approves their own overtime, or overtime they
     * asked for on someone's behalf; rejecting one's own stays allowed, as
     * for leave.
     */
    public function refusal(OvertimeRequest $request, User $by, bool $approving): ?ApprovalRefusal
    {
        if ($request->status !== OvertimeRequestStatus::PENDING) {
            return ApprovalRefusal::notPending(__('overtime.not_pending'));
        }

        if ($approving && $by->employee_id !== null && $by->employee_id === $request->employee_id) {
            return ApprovalRefusal::selfApproval(__('overtime.cannot_approve_own'));
        }

        if ($approving && $request->requested_by !== null && $request->requested_by === $by->id) {
            return ApprovalRefusal::selfApproval(__('overtime.cannot_approve_filed'));
        }

        if (! $this->approvals->isCurrentApprover($request, $by, $approving)) {
            return ApprovalRefusal::notCurrentApprover(__('approval.not_current_approver'));
        }

        return null;
    }

    public function approve(OvertimeRequest $request, User $by): void
    {
        // A chain with steps still to go records this one and stays pending.
        if (! $this->approvals->approve($request, $by)) {
            return;
        }

        $request->update([
            'status' => OvertimeRequestStatus::APPROVED,
            'decided_by' => $by->id,
            'decided_at' => now(),
        ]);

        AuditLog::record('overtime.approved', $request, ['approved_by' => $by->id]);

        $this->notify($this->userOf($request->employee), new OvertimeDecidedNotification($request, true));
    }

    public function reject(OvertimeRequest $request, User $by, string $reason): void
    {
        $this->approvals->reject($request, $by, $reason);

        $request->update([
            'status' => OvertimeRequestStatus::REJECTED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);

        AuditLog::record('overtime.rejected', $request, ['rejected_by' => $by->id, 'reason' => $reason]);

        $this->notify($this->userOf($request->employee), new OvertimeDecidedNotification($request, false));
    }
}
