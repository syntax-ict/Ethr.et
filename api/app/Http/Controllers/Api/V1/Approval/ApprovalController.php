<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Approval;

use App\Enums\ProfileUpdateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\BatchApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Approval\DecidableApprovals;
use App\Services\Attendance\CorrectionDecisionService;
use App\Services\Leave\LeaveDecisionService;
use App\Services\Profile\ProfileUpdateRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ProfileUpdateRequestService $profileUpdates,
        private readonly LeaveDecisionService $leaveDecisions,
        private readonly CorrectionDecisionService $correctionDecisions,
        private readonly DecidableApprovals $approvals,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        Gate::authorize('leave.viewTeam');

        $user = $request->user();

        $items = [];

        // Each kind lists exactly what the caller may decide: the approve
        // permission, the subject inside the caller's org scope (the rule
        // LeaveRequestPolicy::approve and AttendanceCorrectionPolicy::decide
        // apply), and never the caller's own request, which they cannot
        // approve. It used to list direct reports only, so HR and branch- or
        // department-scoped approvers saw a fraction of what they could decide.
        $leaveRequests = $this->approvals->leave($user)
            ->with('employee:id,name,public_id', 'leaveType:id,name,name_am')
            ->orderByDesc('created_at')
            ->get();

        // Summaries follow the request's language (SetLocale): they were
        // English strings, shown as-is on the Amharic screen, and the leave
        // type's own Amharic name went unused (audit N81).
        $amharic = app()->getLocale() === 'am';

        foreach ($leaveRequests as $lr) {
            $type = ($amharic ? $lr->leaveType?->name_am : null)
                ?? $lr->leaveType?->name
                ?? __('approval.leave');

            $items[] = [
                'type' => 'leave',
                'public_id' => $lr->public_id,
                'employee_name' => $lr->employee?->name,
                'employee_public_id' => $lr->employee?->public_id,
                'summary' => __('approval.leave_summary', [
                    'type' => $type,
                    'from' => $lr->start_date->translatedFormat('M d'),
                    'to' => $lr->end_date->translatedFormat('M d'),
                ]),
                'submitted_at' => $lr->created_at,
            ];
        }

        if (class_exists(AttendanceCorrection::class)) {
            $corrections = $this->approvals->corrections($user)
                ->with('employee:id,name,public_id', 'attendanceRecord:id,date')
                ->orderByDesc('created_at')
                ->get();

            foreach ($corrections as $c) {
                // A correction has no `date` of its own — it is the corrected
                // record's. Reading `$c->date` threw on null, so one pending
                // correction in the team turned this whole endpoint into a 500.
                $date = $c->attendanceRecord?->date;

                $items[] = [
                    'type' => 'correction',
                    'public_id' => $c->public_id,
                    'employee_name' => $c->employee?->name,
                    'employee_public_id' => $c->employee?->public_id,
                    'summary' => __('approval.correction_summary', [
                        'date' => $date?->translatedFormat('M d') ?? '—',
                    ]),
                    'submitted_at' => $c->created_at,
                ];
            }
        }

        // Profile-update requests are reviewed by HR (`employee.update`), not by the
        // submitter's supervisor, so they are not filtered by org scope — a
        // reviewer without that permission simply sees none.
        if ($user->hasPermission('employee.update')) {
            $profileUpdates = $this->approvals->profileUpdates($user)
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
                    'summary' => __('approval.profile_update_summary', [
                        'field' => str_replace('_', ' ', $pu->field_name),
                        'old' => $this->displayValue($pu, $pu->old_value, $user) ?? '—',
                        'new' => $this->displayValue($pu, $pu->new_value, $user) ?? '—',
                    ]),
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
        // Each item is authorised on its own below, with the ability its single
        // endpoint checks. This only turns away a caller who could decide none
        // of the three kinds.
        if (Gate::none(['leave.approve', 'correction.approve', 'employee.update'])) {
            throw new AuthorizationException;
        }

        return response()->json(['results' => $this->decideAll($request->input('actions'), $request->user())]);
    }

    /**
     * One result per action, in order. The shape is stated for the API
     * contract, which cannot follow the `array_merge` of the per-kind results
     * (it published `unknown[][]`); every process*Action() below returns a
     * `status` of approved, rejected or error, and a `detail` only with error.
     *
     * @param  array<int, array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     *
     * @scramble-return list<array{public_id: string, status: 'approved'|'rejected'|'error', detail?: string}>
     */
    private function decideAll(array $actions, User $user): array
    {
        $results = [];

        foreach ($actions as $action) {
            $result = match ($action['type']) {
                'leave' => $this->processLeaveAction($action, $user),
                'correction' => $this->processCorrectionAction($action, $user),
                'profile_update' => $this->processProfileUpdateAction($action, $user),
                default => ['status' => 'error', 'detail' => 'Unknown type'],
            };

            $results[] = array_merge(['public_id' => $action['public_id']], $result);
        }

        return $results;
    }

    /**
     * The same decision `LeaveRequestController::approve/reject` makes, through
     * the same policy and the same service. This used to be a copy with no team
     * check, no self-approval guard, a bare id appended to `approved_by` and no
     * webhook — any `leave.approve` holder could approve any leave by id.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, string>
     */
    private function processLeaveAction(array $action, User $user): array
    {
        $lr = LeaveRequest::where('public_id', $action['public_id'])->first();
        if (! $lr) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if (Gate::forUser($user)->denies('approve', $lr)) {
            return ['status' => 'error', 'detail' => 'Not permitted to decide this request'];
        }

        $approving = $action['action'] === 'approve';

        $refusal = $this->leaveDecisions->refusal($lr, $user, $approving);
        if ($refusal !== null) {
            return ['status' => 'error', 'detail' => $refusal->detail];
        }

        if ($approving) {
            $this->leaveDecisions->approve($lr, $user);

            return ['status' => 'approved'];
        }

        $this->leaveDecisions->reject($lr, $user, (string) ($action['reason'] ?? 'Batch rejected'));

        return ['status' => 'rejected'];
    }

    /**
     * The same decision `AttendanceCorrectionController::approve/reject` makes:
     * `correction.approve` and the subject inside the caller's org scope
     * (`AttendanceCorrectionPolicy::decide`), not `leave.approve`.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, string>
     */
    private function processCorrectionAction(array $action, User $user): array
    {
        $correction = AttendanceCorrection::where('public_id', $action['public_id'])->first();
        if (! $correction) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if (Gate::forUser($user)->denies('decide', $correction)) {
            return ['status' => 'error', 'detail' => 'Not permitted to decide this request'];
        }

        $approving = $action['action'] === 'approve';

        $refusal = $this->correctionDecisions->refusal($correction, $user, $approving);
        if ($refusal !== null) {
            return ['status' => 'error', 'detail' => $refusal->detail];
        }

        if ($approving) {
            $this->correctionDecisions->approve($correction, $user);

            return ['status' => 'approved'];
        }

        $this->correctionDecisions->reject($correction, $user, (string) ($action['reason'] ?? 'Batch rejected'));

        return ['status' => 'rejected'];
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
}
