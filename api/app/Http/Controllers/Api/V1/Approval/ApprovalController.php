<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Approval;

use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\ProfileUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\BatchApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Services\Profile\ProfileUpdateRequestService;
use App\Traits\SendsNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    use SendsNotifications;

    public function __construct(private readonly ProfileUpdateRequestService $profileUpdates) {}

    public function pending(Request $request): JsonResponse
    {
        Gate::authorize('leave.viewTeam');

        $user = $request->user();
        $employee = $user->employee;
        $teamIds = $this->getTeamIds($employee);

        $items = [];

        $leaveRequests = LeaveRequest::query()
            ->whereIn('employee_id', $teamIds)
            ->where('status', LeaveStatus::PENDING)
            ->with('employee:id,name,public_id', 'leaveType:id,name')
            ->orderByDesc('created_at')
            ->get();

        foreach ($leaveRequests as $lr) {
            $items[] = [
                'type' => 'leave',
                'public_id' => $lr->public_id,
                'employee_name' => $lr->employee?->name,
                'employee_public_id' => $lr->employee?->public_id,
                'summary' => ($lr->leaveType?->name ?? 'Leave').': '.$lr->start_date->format('M d').' - '.$lr->end_date->format('M d'),
                'submitted_at' => $lr->created_at,
            ];
        }

        if (class_exists(AttendanceCorrection::class)) {
            $corrections = AttendanceCorrection::query()
                ->whereIn('employee_id', $teamIds)
                ->where('status', 'pending')
                ->with('employee:id,name,public_id')
                ->orderByDesc('created_at')
                ->get();

            foreach ($corrections as $c) {
                $items[] = [
                    'type' => 'correction',
                    'public_id' => $c->public_id,
                    'employee_name' => $c->employee?->name,
                    'employee_public_id' => $c->employee?->public_id,
                    'summary' => 'Attendance correction for '.$c->date->format('M d'),
                    'submitted_at' => $c->created_at,
                ];
            }
        }

        // Profile-update requests are reviewed by HR (`employee.update`), not by the
        // submitter's supervisor, so they are not filtered to $teamIds — a reviewer
        // without that permission simply sees none.
        if ($user->hasPermission('employee.update')) {
            $profileUpdates = ProfileUpdateRequest::query()
                ->where('status', ProfileUpdateStatus::PENDING)
                ->with('employee:id,name,public_id')
                ->orderByDesc('created_at')
                ->get();

            foreach ($profileUpdates as $pu) {
                $items[] = [
                    'type' => 'profile_update',
                    'public_id' => $pu->public_id,
                    'employee_name' => $pu->employee?->name,
                    'employee_public_id' => $pu->employee?->public_id,
                    // The delta, not just the field name — approving a bank-account
                    // change is a decision about the values, and a reviewer who has
                    // to open another screen to see them will approve blind.
                    'summary' => sprintf(
                        '%s: %s → %s',
                        str_replace('_', ' ', $pu->field_name),
                        $this->displayValue($pu, $pu->old_value, $user) ?? '—',
                        $this->displayValue($pu, $pu->new_value, $user) ?? '—',
                    ),
                    'submitted_at' => $pu->created_at,
                ];
            }
        }

        usort($items, fn ($a, $b) => $b['submitted_at'] <=> $a['submitted_at']);

        return response()->json([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function batch(BatchApprovalRequest $request): JsonResponse
    {
        Gate::authorize('leave.approve');

        $user = $request->user();
        $results = [];

        foreach ($request->input('actions') as $action) {
            $result = match ($action['type']) {
                'leave' => $this->processLeaveAction($action, $user),
                'correction' => $this->processCorrectionAction($action, $user),
                'profile_update' => $this->processProfileUpdateAction($action, $user),
                default => ['status' => 'error', 'detail' => 'Unknown type'],
            };

            $results[] = array_merge(['public_id' => $action['public_id']], $result);
        }

        return response()->json(['results' => $results]);
    }

    private function processLeaveAction(array $action, $user): array
    {
        $lr = LeaveRequest::where('public_id', $action['public_id'])->first();
        if (! $lr || $lr->status !== LeaveStatus::PENDING) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if ($action['action'] === 'approve') {
            $lr->update([
                'status' => LeaveStatus::APPROVED,
                'approved_by' => array_merge($lr->approved_by ?? [], [$user->id]),
            ]);

            $balance = LeaveBalance::where('employee_id', $lr->employee_id)
                ->where('leave_type_id', $lr->leave_type_id)
                ->where('year', $lr->start_date->year)
                ->first();

            if ($balance) {
                $balance->update([
                    'used_days' => $balance->used_days + $lr->days,
                    'pending_days' => max(0, $balance->pending_days - $lr->days),
                ]);
            }

            AuditLog::record('leave.approved', $lr);

            $this->notify($this->userOf($lr->employee), new LeaveApprovedNotification($lr));

            return ['status' => 'approved'];
        }

        $lr->update([
            'status' => LeaveStatus::REJECTED,
            'rejected_by' => $user->id,
            'rejected_reason' => $action['reason'] ?? 'Batch rejected',
        ]);

        $balance = LeaveBalance::where('employee_id', $lr->employee_id)
            ->where('leave_type_id', $lr->leave_type_id)
            ->where('year', $lr->start_date->year)
            ->first();

        if ($balance) {
            $balance->update([
                'pending_days' => max(0, $balance->pending_days - $lr->days),
            ]);
        }

        AuditLog::record('leave.rejected', $lr);

        $this->notify($this->userOf($lr->employee), new LeaveRejectedNotification($lr));

        return ['status' => 'rejected'];
    }

    private function processCorrectionAction(array $action, $user): array
    {
        if (! class_exists(AttendanceCorrection::class)) {
            return ['status' => 'error', 'detail' => 'Corrections not available'];
        }

        $correction = AttendanceCorrection::where('public_id', $action['public_id'])->first();
        if (! $correction || $correction->status !== CorrectionStatus::PENDING) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        $approved = $action['action'] === 'approve';

        // attendance_corrections has no reviewed_by/reviewed_at/review_notes
        // columns — approval history lives in the approval_chain JSON array,
        // same as the single-item AttendanceCorrectionController::approve()/reject().
        $chain = $correction->approval_chain ?? [];
        $chain[] = [
            'user_id' => $user->id,
            'role' => $user->role?->value,
            'action' => $approved ? 'approved' : 'rejected',
            'reason' => $approved ? null : ($action['reason'] ?? 'Batch rejected'),
            'at' => now()->toIso8601String(),
        ];

        $correction->update([
            'approval_chain' => $chain,
            'status' => $approved ? CorrectionStatus::APPROVED : CorrectionStatus::REJECTED,
        ]);

        if ($approved) {
            $record = $correction->attendanceRecord;
            if ($record) {
                $updateData = ['metadata' => array_merge($record->metadata ?? [], ['is_corrected' => true, 'correction_id' => $correction->public_id])];

                if ($correction->proposed_check_in) {
                    $updateData['check_in'] = $correction->proposed_check_in;
                }
                if ($correction->proposed_check_out) {
                    $updateData['check_out'] = $correction->proposed_check_out;
                }

                $record->update($updateData);
            }
        }

        AuditLog::record($approved ? 'correction.approved' : 'correction.rejected', $correction);

        return ['status' => $approved ? 'approved' : 'rejected'];
    }

    /**
     * Mirrors ProfileUpdateRequestResource's masking rule: an account number is
     * shown in full only to a reviewer who also holds `employee.viewFinancial`.
     * `employee.update` alone is enough to approve a name change but does not
     * carry the right to read bank details.
     */
    private function displayValue(ProfileUpdateRequest $request, ?string $value, User $user): ?string
    {
        if ($value === null || $request->field_name !== 'bank_account_number') {
            return $value;
        }

        if ($user->hasPermission('employee.viewFinancial')) {
            return $value;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, string>
     */
    private function processProfileUpdateAction(array $action, User $user): array
    {
        // The batch endpoint gates on `leave.approve`; reviewing a profile change
        // is a different authority, so it is checked per item rather than letting a
        // supervisor approve a bank-account change by batching it with leave.
        if (! $user->hasPermission('employee.update')) {
            return ['status' => 'error', 'detail' => 'Not permitted to review profile updates'];
        }

        $profileUpdate = ProfileUpdateRequest::where('public_id', $action['public_id'])->first();
        if (! $profileUpdate || $profileUpdate->status !== ProfileUpdateStatus::PENDING) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if ($action['action'] === 'approve') {
            $this->profileUpdates->approve($profileUpdate, $user, $action['reason'] ?? null);

            return ['status' => 'approved'];
        }

        $this->profileUpdates->reject($profileUpdate, $user, $action['reason'] ?? 'Batch rejected');

        return ['status' => 'rejected'];
    }

    private function getTeamIds($employee): array
    {
        if (! $employee) {
            return [];
        }

        return Employee::query()
            ->where('supervisor_id', $employee->id)
            ->pluck('id')
            ->toArray();
    }
}
