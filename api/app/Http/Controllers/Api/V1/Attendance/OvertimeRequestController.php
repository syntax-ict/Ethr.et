<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\OrgScope;
use App\Enums\OvertimeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RejectOvertimeRequestRequest;
use App\Http\Requests\Attendance\StoreOvertimeRequestRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\OvertimeRequestedNotification;
use App\Services\Approval\ApprovalEngine;
use App\Services\Attendance\OvertimeDecisionService;
use App\Traits\SendsNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Overtime asked for before it is worked (blueprint S8). Filing mirrors
 * attendance corrections; deciding goes through the approval engine like
 * leave, so an organisation's chain for `overtime` applies to it.
 */
class OvertimeRequestController extends Controller
{
    use SendsNotifications;

    public function __construct(
        private readonly OvertimeDecisionService $decisions,
        private readonly ApprovalEngine $approvals,
    ) {}

    /** The caller's own requests, newest first. */
    public function my(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('overtime.request');

        $user = $request->user();

        $query = OvertimeRequest::query()
            ->with('employee', 'approvalInstance.actions.actor')
            ->orderByDesc('date');

        if ($user->employee_id === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('employee_id', $user->employee_id);
        }

        return OvertimeRequestResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * Every request the caller may see: all of them with `overtime.viewAll`,
     * otherwise those of the employees an approver reaches.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user->hasPermission('overtime.viewAll')) {
            Gate::authorize('overtime.approve');
        }

        $query = OvertimeRequest::query()
            ->with('employee', 'approvalInstance.actions.actor')
            ->orderByDesc('date');

        if (! $user->hasPermission('overtime.viewAll') && $user->orgScope() !== OrgScope::ALL) {
            $query->whereHas('employee', fn (Builder $q) => $user->scopeAccessibleEmployees($q));
        }

        $status = $request->query('status');
        if (is_string($status) && OvertimeRequestStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return OvertimeRequestResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        $publicId = $request->validated('employee_public_id');

        $employee = $publicId === null
            ? ($user->employee_id === null ? null : Employee::query()->find($user->employee_id))
            : Employee::query()->where('public_id', $publicId)->first();

        if (! $employee instanceof Employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-employee-record',
                'title' => 'No Employee Record',
                'status' => 422,
                'detail' => __('overtime.no_employee_record'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        Gate::authorize('file', [OvertimeRequest::class, $employee]);

        $date = $request->validated('date');
        $open = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->whereIn('status', [OvertimeRequestStatus::PENDING, OvertimeRequestStatus::APPROVED])
            ->exists();
        if ($open) {
            return response()->json([
                'type' => 'https://ethr.et/errors/overtime-already-requested',
                'title' => 'Overtime Already Requested',
                'status' => 422,
                'detail' => __('overtime.already_requested'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $overtime = OvertimeRequest::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'date' => $date,
            'planned_minutes' => (int) $request->validated('planned_minutes'),
            'reason' => $request->validated('reason'),
            'status' => OvertimeRequestStatus::PENDING,
            'requested_by' => $user->id,
        ]);

        AuditLog::record('overtime.requested', $overtime, [
            'date' => $date,
            'planned_minutes' => $overtime->planned_minutes,
        ]);

        // The person the chain's first step names, else the supervisor.
        $employee->loadMissing('supervisor.user');
        $recipient = $this->approvals->namedApprover($overtime) ?? $employee->supervisor?->user;
        if ($recipient instanceof User) {
            $this->notify($recipient, new OvertimeRequestedNotification($overtime));
        }

        $overtime->load('employee', 'approvalInstance.actions.actor');

        return (new OvertimeRequestResource($overtime))->response()->setStatusCode(201);
    }

    public function approve(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        Gate::authorize('decide', $overtimeRequest);

        $refusal = $this->decisions->refusal($overtimeRequest, $request->user(), approving: true);
        if ($refusal !== null) {
            return $refusal->toResponse();
        }

        $this->decisions->approve($overtimeRequest, $request->user());

        $overtimeRequest->load('employee', 'approvalInstance.actions.actor');

        return response()->json(new OvertimeRequestResource($overtimeRequest));
    }

    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        Gate::authorize('decide', $overtimeRequest);

        $refusal = $this->decisions->refusal($overtimeRequest, $request->user(), approving: false);
        if ($refusal !== null) {
            return $refusal->toResponse();
        }

        $this->decisions->reject($overtimeRequest, $request->user(), (string) $request->validated('reason'));

        $overtimeRequest->load('employee', 'approvalInstance.actions.actor');

        return response()->json(new OvertimeRequestResource($overtimeRequest));
    }

    /** Withdraw a request that is still waiting. */
    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        Gate::authorize('cancel', $overtimeRequest);

        if ($overtimeRequest->status !== OvertimeRequestStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('overtime.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $overtimeRequest->update(['status' => OvertimeRequestStatus::CANCELLED]);
        AuditLog::record('overtime.cancelled', $overtimeRequest);

        $overtimeRequest->load('employee', 'approvalInstance.actions.actor');

        return response()->json(new OvertimeRequestResource($overtimeRequest));
    }
}
