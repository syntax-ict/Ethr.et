<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\CorrectionStatus;
use App\Enums\OrgScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RejectCorrectionRequest;
use App\Http\Requests\Attendance\StoreCorrectionRequest;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use App\Services\Attendance\CorrectionDecisionService;
use App\Traits\SendsNotifications;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Who may do what is `AttendanceCorrectionPolicy`; see its docblock for the rule.
 */
class AttendanceCorrectionController extends Controller
{
    // Notifications go out after the correction is written, so a failed
    // delivery must not turn a committed change into a 500. ApprovalController
    // approves the same corrections the same way.
    use SendsNotifications;

    /**
     * What AttendanceRecordResource reads through the nested record; without
     * it a list of corrections lazy-loads both per row.
     */
    private const RECORD_RELATIONS = ['attendanceRecord.employee', 'attendanceRecord.shift'];

    public function __construct(private readonly CorrectionDecisionService $decisions) {}

    public function store(StoreCorrectionRequest $request): JsonResponse
    {
        $record = AttendanceRecord::where('public_id', $request->validated('attendance_record_public_id'))->firstOrFail();

        Gate::authorize('file', [AttendanceCorrection::class, $record]);

        // The correction belongs to the employee whose punches it changes. It
        // used to take the filer's own employee id first, so HR filing on
        // someone's behalf produced a correction "by" HR against another
        // person's record — routed to HR's supervisor and approved as HR's.
        $correction = AttendanceCorrection::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $record->employee_id,
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
        $supervisor = $correction->employee?->supervisor;
        if ($supervisor?->user) {
            $this->notify($supervisor->user, new AttendanceCorrectionRequestedNotification($correction));
        }

        $correction->load('attendanceRecord', 'employee');

        return (new AttendanceCorrectionResource($correction))
            ->response()
            ->setStatusCode(201);
    }

    public function my(Request $request): AnonymousResourceCollection
    {
        // The caller's own corrections, whatever their state — what the "My
        // requests" view lists. Nothing served this before, though
        // `correction.viewOwn` has always been granted to everyone.
        Gate::authorize('correction.viewOwn');

        $user = $request->user();

        $query = AttendanceCorrection::query()
            ->with('attendanceRecord', 'employee')
            ->orderByDesc('created_at');

        // A login with no employee record has no corrections. Stated as an
        // impossible predicate because `where('employee_id', null)` would
        // become `IS NULL` and match rows rather than none.
        if ($user->employee_id === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('employee_id', $user->employee_id);
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        return AttendanceCorrectionResource::collection($this->paginateWithRecords($query, $request->integer('per_page', 25)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('correction.viewAll');

        $query = AttendanceCorrection::query()
            ->with('attendanceRecord', 'employee');

        $this->limitToReachableEmployees($query, $request->user());

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $query->orderByDesc('created_at');

        return AttendanceCorrectionResource::collection($this->paginateWithRecords($query, $request->integer('per_page', 25)));
    }

    public function pending(Request $request): AnonymousResourceCollection
    {
        // The approval queue: pending corrections of the employees the caller
        // may decide for, never their own (which they could not approve).
        Gate::authorize('correction.viewPending');

        $user = $request->user();

        $query = AttendanceCorrection::query()
            ->where('status', CorrectionStatus::PENDING)
            ->with('attendanceRecord', 'employee')
            ->orderByDesc('created_at');

        $this->limitToReachableEmployees($query, $user);

        if ($user->employee_id !== null) {
            $query->where('employee_id', '!=', $user->employee_id);
        }

        return AttendanceCorrectionResource::collection($this->paginateWithRecords($query, $request->integer('per_page', 25)));
    }

    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('decide', $correction);

        $user = $request->user();

        $refusal = $this->decisions->refusal($correction, $user, approving: true);
        if ($refusal !== null) {
            return $refusal->toResponse();
        }

        $this->decisions->approve($correction, $user);

        $correction->load('attendanceRecord', 'employee');

        return response()->json(new AttendanceCorrectionResource($correction));
    }

    public function payrollImpact(AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('previewImpact', $correction);

        $correction->load('attendanceRecord', 'employee');
        $record = $correction->attendanceRecord;
        $employee = $correction->employee;

        $originalMinutes = 0;
        $proposedMinutes = 0;
        $recordCheckIn = null;
        $recordCheckOut = null;

        if ($record !== null) {
            $recordCheckIn = $record->check_in;
            $recordCheckOut = $record->check_out;
        }

        if ($recordCheckIn && $recordCheckOut) {
            $originalMinutes = (int) Carbon::parse($recordCheckIn)
                ->diffInMinutes(Carbon::parse($recordCheckOut));
        }

        $proposedIn = $correction->proposed_check_in ?? $recordCheckIn;
        $proposedOut = $correction->proposed_check_out ?? $recordCheckOut;

        if ($proposedIn && $proposedOut) {
            $proposedMinutes = (int) Carbon::parse($proposedIn)
                ->diffInMinutes(Carbon::parse($proposedOut));
        }

        // Standard: 176 hours/month (8h × 22 days)
        $monthlyMinutes = 176 * 60;
        $salaryCents = $employee !== null ? $employee->salary_cents : 0;
        $minuteRateCents = (int) round($salaryCents / $monthlyMinutes);

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
        Gate::authorize('decide', $correction);

        $user = $request->user();

        $refusal = $this->decisions->refusal($correction, $user, approving: false);
        if ($refusal !== null) {
            return $refusal->toResponse();
        }

        $this->decisions->reject($correction, $user, (string) $request->input('reason'));

        $correction->load('attendanceRecord', 'employee');

        return response()->json(new AttendanceCorrectionResource($correction));
    }

    /**
     * One page of corrections, with what `AttendanceRecordResource` reads
     * through each nested record loaded in one query rather than one per row.
     *
     * Loaded on the page rather than with `with()` on the query: Scramble reads
     * a `with()` of nested relations as a schema change to the resource and
     * would rewrite `attendance_record` in the published contract.
     *
     * @param  Builder<AttendanceCorrection>  $query
     * @return LengthAwarePaginator<int, AttendanceCorrection>
     */
    private function paginateWithRecords(Builder $query, int $perPage): LengthAwarePaginator
    {
        $page = $query->paginate($perPage);
        (new EloquentCollection($page->items()))->loadMissing(self::RECORD_RELATIONS);

        return $page;
    }

    /**
     * The list counterpart of `AttendanceCorrectionPolicy::decide` — the same
     * org scope `AttendanceController::index` applies to records.
     *
     * @param  Builder<AttendanceCorrection>  $query
     */
    private function limitToReachableEmployees(Builder $query, User $user): void
    {
        if ($user->orgScope() === OrgScope::ALL) {
            return;
        }

        $query->whereHas('employee', fn (Builder $q) => $user->scopeAccessibleEmployees($q));
    }
}
