<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApprovedNotification;
use App\Services\Approval\ApprovalEngine;
use App\Services\Payroll\PayrollPeriodLock;
use App\Support\ApprovalRefusal;
use App\Support\AttendanceWebhookPayload;
use App\Traits\DispatchesWebhooks;
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
    use DispatchesWebhooks, SendsNotifications;

    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly EnteredAttendance $entered,
        private readonly PayrollPeriodLock $periodLock,
        private readonly ApprovalEngine $approvals,
    ) {}

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

        // Nor can the person who filed it: a correction rewrites punches that
        // payroll pays, so filing and approving are two people's acts. On
        // production on 2026-10-12 one admin filed a correction on an
        // employee's record and approved it seconds later (attendance QA A4).
        // Corrections filed before the filer was recorded carry no such entry
        // and are decided as before.
        if ($approving && self::filedBy($correction) === $by->public_id) {
            return ApprovalRefusal::selfApproval(__('correction.cannot_approve_filed'));
        }

        // A lateness decision changes pay, not punches. Whoever approves
        // corrections (a supervisor, usually) may still reject one, but the
        // approval that lets payroll deduct belongs to payroll.
        if ($approving && $correction->kind === CorrectionKind::LATENESS && ! $by->hasPermission('payroll.process')) {
            return ApprovalRefusal::missingPermission(__('correction.lateness_needs_payroll'));
        }

        if (! $this->approvals->isCurrentApprover($correction, $by, $approving)) {
            return ApprovalRefusal::notCurrentApprover(__('approval.not_current_approver'));
        }

        // A day already paid in an approved run is not rewritten (PayrollPeriodLock).
        // A missed-punch claim has no record, so its own date is the day.
        $record = $correction->attendanceRecord;
        $day = $record !== null ? $record->date : $correction->date;
        if ($approving && $day !== null) {
            return $this->periodLock->refusal($correction->employee_id, $day);
        }

        return null;
    }

    /**
     * The first entry of a correction's approval chain: who filed it. By
     * public id — the chain is returned by the API.
     *
     * @return array{action: string, user_public_id: string, role: string, at: string}
     */
    public static function submittedEntry(User $by): array
    {
        return [
            'action' => 'submitted',
            'user_public_id' => $by->public_id,
            'role' => $by->role->value,
            'at' => now()->toIso8601String(),
        ];
    }

    private static function filedBy(AttendanceCorrection $correction): ?string
    {
        $chain = $correction->getAttribute('approval_chain');

        foreach (is_array($chain) ? $chain : [] as $entry) {
            if (is_array($entry) && ($entry['action'] ?? null) === 'submitted') {
                return $entry['user_public_id'] ?? null;
            }
        }

        return null;
    }

    public function approve(AttendanceCorrection $correction, User $by): void
    {
        // A chain with steps still to go records this one and stays pending.
        if (! $this->approvals->approve($correction, $by)) {
            return;
        }

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

        $record = match ($correction->kind) {
            CorrectionKind::MISSED_PUNCH => $this->createClaimedRecord($correction, $by),
            default => $correction->attendanceRecord,
        };
        $before = null;
        if ($record && $correction->kind === CorrectionKind::LATENESS) {
            // Moves no punch: payroll reads what was decided from the record.
            $before = ['status' => $record->status->value];
            $record->update(['metadata' => array_merge($record->metadata ?? [], [
                'pay_deduct_minutes' => (int) $correction->deduct_minutes,
                'lateness_correction_id' => $correction->public_id,
            ])]);
        } elseif ($record && $correction->kind === CorrectionKind::CORRECTION) {
            // The punches as they stood, kept: approving wrote the proposed
            // times over them and the audit row named only the approver, so
            // the original record of when someone clocked in was gone
            // (audit N88). The first original survives later corrections.
            $before = [
                'check_in' => $record->check_in?->toIso8601String(),
                'check_out' => $record->check_out?->toIso8601String(),
                'status' => $record->status->value,
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

            // The status was read from the punches being replaced. A
            // correction that moved a record to a full shift on time left it
            // `early_leave` on production (attendance QA A5).
            $this->engine->reclassify($record);
        }

        AuditLog::record('correction.approved', $correction, [
            'approved_by' => $by->id,
            'before' => $before,
            'after' => $record === null ? null : [
                'check_in' => $record->check_in?->toIso8601String(),
                'check_out' => $record->check_out?->toIso8601String(),
                'status' => $record->status->value,
            ],
        ]);

        if ($record !== null && $correction->employee !== null) {
            $this->webhook($record->tenant_id, 'attendance.corrected', [
                ...AttendanceWebhookPayload::of($record->fresh() ?? $record, $correction->employee),
                'correction_public_id' => $correction->public_id,
            ]);
        }

        $this->notify($this->userOf($correction->employee), new AttendanceCorrectionApprovedNotification($correction, true));
    }

    /**
     * The record a missed-punch claim says should have existed, entered by
     * the same steps HR's manual entry takes (EnteredAttendance), and linked
     * back to the claim so the correction points at the record it made.
     */
    private function createClaimedRecord(AttendanceCorrection $correction, User $by): ?AttendanceRecord
    {
        $employee = $correction->employee;

        if ($employee === null || $correction->date === null || $correction->proposed_check_in === null) {
            return null;
        }

        $record = $this->entered->enter(
            $employee,
            $correction->date->format('Y-m-d'),
            $correction->proposed_check_in,
            $correction->proposed_check_out,
            [
                'reason' => $correction->reason,
                'entered_by' => $by->id,
                'is_corrected' => true,
                'correction_id' => $correction->public_id,
                'missed_punch' => true,
            ],
            'missed-punch:'.$correction->public_id,
        )->record;

        $correction->update(['attendance_record_id' => $record->id]);
        $correction->setRelation('attendanceRecord', $record);

        return $record;
    }

    public function reject(AttendanceCorrection $correction, User $by, string $reason): void
    {
        $this->approvals->reject($correction, $by, $reason);

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
