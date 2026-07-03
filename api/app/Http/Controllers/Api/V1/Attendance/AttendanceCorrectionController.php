<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\CorrectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RejectCorrectionRequest;
use App\Http\Requests\Attendance\StoreCorrectionRequest;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Notifications\AttendanceCorrectionApprovedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceCorrectionController extends Controller
{
    public function store(StoreCorrectionRequest $request): JsonResponse
    {
        Gate::authorize('correction.create');

        $record = AttendanceRecord::where('public_id', $request->validated('attendance_record_public_id'))->firstOrFail();
        $user = $request->user();

        $correction = AttendanceCorrection::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $user->employee_id ?? $record->employee_id,
            'reason' => $request->validated('reason'),
            'proposed_check_in' => $request->validated('proposed_check_in'),
            'proposed_check_out' => $request->validated('proposed_check_out'),
            'status' => CorrectionStatus::PENDING,
            'approval_chain' => [],
        ]);

        AuditLog::record('correction.submitted', $correction, [
            'attendance_record_public_id' => $record->public_id,
        ]);

        // Notify supervisor
        $employee = $correction->employee ?? $user->employee;
        $supervisor = $employee?->supervisor;
        if ($supervisor?->user) {
            $supervisor->user->notify(new AttendanceCorrectionRequestedNotification($correction));
        }

        $correction->load('attendanceRecord', 'employee');

        return (new AttendanceCorrectionResource($correction))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('correction.viewAll');

        $query = AttendanceCorrection::query()
            ->with('attendanceRecord', 'employee');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $query->orderByDesc('created_at');

        return AttendanceCorrectionResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function pending(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('correction.viewPending');

        $query = AttendanceCorrection::query()
            ->where('status', CorrectionStatus::PENDING)
            ->with('attendanceRecord', 'employee')
            ->orderByDesc('created_at');

        return AttendanceCorrectionResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('correction.approve');

        if ($correction->status !== CorrectionStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('correction.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $user = $request->user();
        $chain = $correction->approval_chain ?? [];
        $chain[] = [
            'user_id' => $user->id,
            'role' => $user->role?->value,
            'action' => 'approved',
            'at' => now()->toIso8601String(),
        ];

        $correction->update([
            'approval_chain' => $chain,
            'status' => CorrectionStatus::APPROVED,
        ]);

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

        AuditLog::record('correction.approved', $correction, [
            'approved_by' => $user->id,
        ]);

        // Notify employee
        $empUser = $correction->employee?->user;
        if ($empUser) {
            $empUser->notify(new AttendanceCorrectionApprovedNotification($correction, true));
        }

        $correction->load('attendanceRecord', 'employee');

        return response()->json(new AttendanceCorrectionResource($correction));
    }

    public function payrollImpact(AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('correction.viewPending');

        $correction->load('attendanceRecord', 'employee');
        $record = $correction->attendanceRecord;
        $employee = $correction->employee;

        $originalMinutes = 0;
        $proposedMinutes = 0;

        if ($record?->check_in && $record->check_out) {
            $originalMinutes = (int) Carbon::parse($record->check_in)
                ->diffInMinutes(Carbon::parse($record->check_out));
        }

        $proposedIn = $correction->proposed_check_in ?? $record?->check_in;
        $proposedOut = $correction->proposed_check_out ?? $record?->check_out;

        if ($proposedIn && $proposedOut) {
            $proposedMinutes = (int) Carbon::parse($proposedIn)
                ->diffInMinutes(Carbon::parse($proposedOut));
        }

        // Standard: 176 hours/month (8h × 22 days)
        $monthlyMinutes = 176 * 60;
        $salaryCents = $employee?->salary_cents ?? 0;
        $minuteRateCents = $monthlyMinutes > 0 ? (int) round($salaryCents / $monthlyMinutes) : 0;

        $diffMinutes = $proposedMinutes - $originalMinutes;
        $impactCents = $minuteRateCents * $diffMinutes;

        return response()->json([
            'original_hours' => round($originalMinutes / 60, 2),
            'proposed_hours' => round($proposedMinutes / 60, 2),
            'difference_minutes' => $diffMinutes,
            'estimated_impact_cents' => $impactCents,
            'hourly_rate_cents' => $minuteRateCents * 60,
            'in_open_payroll_period' => true,
            'currency' => 'ETB',
        ]);
    }

    public function reject(RejectCorrectionRequest $request, AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('correction.approve');

        if ($correction->status !== CorrectionStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('correction.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $user = $request->user();
        $chain = $correction->approval_chain ?? [];
        $chain[] = [
            'user_id' => $user->id,
            'role' => $user->role?->value,
            'action' => 'rejected',
            'reason' => $request->input('reason'),
            'at' => now()->toIso8601String(),
        ];

        $correction->update([
            'approval_chain' => $chain,
            'status' => CorrectionStatus::REJECTED,
        ]);

        AuditLog::record('correction.rejected', $correction, [
            'rejected_by' => $user->id,
            'reason' => $request->input('reason'),
        ]);

        // Notify employee of rejection
        $empUser = $correction->employee?->user;
        if ($empUser) {
            $empUser->notify(new AttendanceCorrectionApprovedNotification($correction, false));
        }

        $correction->load('attendanceRecord', 'employee');

        return response()->json(new AttendanceCorrectionResource($correction));
    }
}
