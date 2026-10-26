<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ReviewIntegrityFlagRequest;
use App\Http\Resources\AttendanceIntegrityFlagResource;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendancePunchRejectedNotification;
use App\Services\Payroll\PayrollPeriodLock;
use App\Traits\SendsNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The integrity review queue: punches the IntegrityInspector could not trust
 * on their face. Whoever may resolve attendance conflicts decides them -
 * within their org scope, and never their own - and a rejection voids the
 * punch and tells the employee why.
 */
class AttendanceIntegrityController extends Controller
{
    use SendsNotifications;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewConflicts');

        $query = $this->visible($request->user())
            ->with(['employee', 'record.shift', 'reviewer'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderByDesc('id');

        $status = $request->input('filter.status');
        if (is_string($status) && in_array($status, AttendanceIntegrityFlag::STATUSES, true)) {
            $query->where('status', $status);
        }

        $severity = $request->input('filter.severity');
        if (is_string($severity) && in_array($severity, AttendanceIntegrityFlag::SEVERITIES, true)) {
            $query->where('severity', $severity);
        }

        return AttendanceIntegrityFlagResource::collection(
            $query->paginate(min($request->integer('per_page', 25), 100))
        );
    }

    /** Open flags by severity and type, for the dashboard and the queue header. */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewConflicts');

        $open = $this->visible($request->user())->where('status', 'open');

        return response()->json([
            'open' => (clone $open)->count(),
            'by_severity' => (clone $open)->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity'),
            'by_type' => (clone $open)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
        ]);
    }

    public function review(ReviewIntegrityFlagRequest $request, string $flag): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('attendance.resolveConflicts')) {
            abort(403);
        }

        $model = $this->visible($user)->with(['employee', 'record'])->where('public_id', $flag)->first();
        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(AttendanceIntegrityFlag::class, [$flag]);
        }

        // Deciding whether your own punch counts is deciding your own hours,
        // which corrections and conflicts already refuse.
        if ($user->employee_id !== null && $model->employee_id === $user->employee_id) {
            return $this->problem(403, 'Forbidden', __('attendance.integrity_self_review'));
        }

        if ($model->status !== 'open') {
            return $this->problem(422, 'Invalid State', __('attendance.integrity_already_reviewed'));
        }

        $decision = (string) $request->validated('decision');
        $note = $request->validated('note');

        // Rejecting voids the punch, which a reprocess of a paid day would then
        // drop (PayrollPeriodLock).
        if ($decision === 'reject' && $model->record !== null) {
            app(PayrollPeriodLock::class)->assertOpen($model->record->employee_id, $model->record->date);
        }

        $model->update([
            'status' => $decision === 'accept' ? 'accepted' : 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $record = $model->record;
        if ($decision === 'reject' && $record !== null && $record->status !== AttendanceStatus::VOIDED) {
            $record->update([
                'status' => AttendanceStatus::VOIDED,
                'metadata' => array_merge($record->metadata ?? [], [
                    'voided_reason' => 'integrity_rejected',
                    'integrity_flag' => $model->public_id,
                    'voided_at' => now()->toIso8601String(),
                ]),
            ]);

            // The punch is gone, so the record's other open questions are
            // answered with it.
            AttendanceIntegrityFlag::query()
                ->where('attendance_record_id', $record->id)
                ->where('status', 'open')
                ->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

            $employeeUser = User::query()->where('employee_id', $record->employee_id)->first();
            // Delivered best-effort: the rejection is already saved, and a mail
            // outage must not turn it into an error for the reviewer.
            $this->notify($employeeUser, new AttendancePunchRejectedNotification($record, (string) ($note ?? '')));
        }

        AuditLog::record('attendance.integrity_'.($decision === 'accept' ? 'accepted' : 'rejected'), $model, [
            'type' => $model->type,
            'severity' => $model->severity,
            'record' => $record?->public_id,
        ]);

        return response()->json(new AttendanceIntegrityFlagResource($model->fresh(['employee', 'record.shift', 'reviewer'])));
    }

    /** @return Builder<AttendanceIntegrityFlag> */
    private function visible(User $user): Builder
    {
        return AttendanceIntegrityFlag::query()->visibleTo($user);
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/'.($status === 403 ? 'forbidden' : 'invalid-state'),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}
