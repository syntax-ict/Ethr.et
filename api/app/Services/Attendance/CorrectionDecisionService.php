<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApprovedNotification;
use App\Support\ApprovalRefusal;
use App\Traits\SendsNotifications;

/**
 * Approving or rejecting an attendance correction — the one implementation
 * behind both `AttendanceCorrectionController` and the batch approval centre.
 *
 * Authorisation is the caller's job and goes through `AttendanceCorrectionPolicy::decide`
 * on both paths; this class owns what happens once it has passed.
 */
class CorrectionDecisionService
{
    use SendsNotifications;

    /**
     * The state checks that follow authorisation, or null when the decision may go ahead.
     *
     * A correction's subject cannot approve it: a supervisor's org scope
     * includes themselves, so without this an approver could rewrite their own
     * punches. Rejecting one's own is harmless and stays allowed, as for leave.
     */
    public function refusal(AttendanceCorrection $correction, User $by, bool $approving): ?ApprovalRefusal
    {
        if ($correction->status !== CorrectionStatus::PENDING) {
            return ApprovalRefusal::notPending(__('correction.not_pending'));
        }

        if ($approving && $by->employee_id !== null && $by->employee_id === $correction->employee_id) {
            return ApprovalRefusal::selfApproval(__('correction.cannot_approve_own'));
        }

        return null;
    }

    public function approve(AttendanceCorrection $correction, User $by): void
    {
        $chain = $correction->approval_chain ?? [];
        $chain[] = [
            'user_id' => $by->id,
            'role' => $by->role->value,
            'action' => 'approved',
            'at' => now()->toIso8601String(),
        ];

        $correction->update([
            'approval_chain' => $chain,
            'status' => CorrectionStatus::APPROVED,
        ]);

        $record = $correction->attendanceRecord;
        $before = null;
        if ($record) {
            // The punches as they stood, kept: approving wrote the proposed
            // times over them and the audit row named only the approver, so
            // the original record of when someone clocked in was gone
            // (audit N88). The first original survives later corrections.
            $before = [
                'check_in' => $record->check_in?->toIso8601String(),
                'check_out' => $record->check_out?->toIso8601String(),
            ];
            $metadata = $record->metadata ?? [];
            $updateData = ['metadata' => array_merge($metadata, [
                'is_corrected' => true,
                'correction_id' => $correction->public_id,
                'original_check_in' => $metadata['original_check_in'] ?? $before['check_in'],
                'original_check_out' => $metadata['original_check_out'] ?? $before['check_out'],
            ])];

            if ($correction->proposed_check_in) {
                $updateData['check_in'] = $correction->proposed_check_in;
            }
            if ($correction->proposed_check_out) {
                $updateData['check_out'] = $correction->proposed_check_out;
            }

            $record->update($updateData);
        }

        AuditLog::record('correction.approved', $correction, [
            'approved_by' => $by->id,
            'before' => $before,
            'after' => $record === null ? null : [
                'check_in' => $record->check_in?->toIso8601String(),
                'check_out' => $record->check_out?->toIso8601String(),
            ],
        ]);

        $this->notify($this->userOf($correction->employee), new AttendanceCorrectionApprovedNotification($correction, true));
    }

    public function reject(AttendanceCorrection $correction, User $by, string $reason): void
    {
        $chain = $correction->approval_chain ?? [];
        $chain[] = [
            'user_id' => $by->id,
            'role' => $by->role->value,
            'action' => 'rejected',
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];

        $correction->update([
            'approval_chain' => $chain,
            'status' => CorrectionStatus::REJECTED,
        ]);

        AuditLog::record('correction.rejected', $correction, [
            'rejected_by' => $by->id,
            'reason' => $reason,
        ]);

        $this->notify($this->userOf($correction->employee), new AttendanceCorrectionApprovedNotification($correction, false));
    }
}
