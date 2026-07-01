<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveDayCalculator;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveRequestController extends Controller
{
    use DispatchesWebhooks;
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveDayCalculator $dayCalculator,
    ) {}

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        Gate::authorize('leave.request');

        $user = $request->user();
        $employee = Employee::findOrFail($user->employee_id);

        $leaveType = LeaveType::where('public_id', $request->validated('leave_type_public_id'))->firstOrFail();

        if (! $leaveType->isAvailableForGender($employee->gender)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/leave-restriction',
                'title' => 'Leave Type Restricted',
                'status' => 422,
                'detail' => __('leave.gender_restricted'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $startDate = \Carbon\Carbon::parse($request->validated('start_date'));
        $endDate = \Carbon\Carbon::parse($request->validated('end_date'));

        $days = $this->dayCalculator->calculateDays(
            $startDate,
            $endDate,
            $employee->tenant_id,
            $employee->branch_id,
        );

        if ($days <= 0) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-dates',
                'title' => 'Invalid Leave Dates',
                'status' => 422,
                'detail' => __('leave.no_working_days'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        if ($leaveType->min_notice_days > 0) {
            $noticeDays = now()->diffInDays($startDate, false);
            if ($noticeDays < $leaveType->min_notice_days) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/notice-period',
                    'title' => 'Insufficient Notice',
                    'status' => 422,
                    'detail' => __('leave.min_notice', ['days' => $leaveType->min_notice_days]),
                ], 422)->header('Content-Type', 'application/problem+json');
            }
        }

        if ($leaveType->max_consecutive && $days > $leaveType->max_consecutive) {
            return response()->json([
                'type' => 'https://ethr.et/errors/max-consecutive',
                'title' => 'Exceeds Maximum Consecutive Days',
                'status' => 422,
                'detail' => __('leave.max_consecutive', ['days' => $leaveType->max_consecutive]),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $year = $startDate->year;
        $balance = $this->balanceService->getOrCreateBalance($employee, $leaveType, $year);
        $remaining = $balance->remainingDays();

        if ($days > $remaining) {
            return response()->json([
                'type' => 'https://ethr.et/errors/insufficient-balance',
                'title' => 'Insufficient Leave Balance',
                'status' => 422,
                'detail' => __('leave.insufficient_balance', ['remaining' => $remaining, 'requested' => $days]),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::PENDING, LeaveStatus::APPROVED])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();

        if ($overlap) {
            return response()->json([
                'type' => 'https://ethr.et/errors/overlap',
                'title' => 'Overlapping Leave Request',
                'status' => 422,
                'detail' => __('leave.overlap'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
            'reason' => $request->validated('reason'),
            'attachment_path' => $request->validated('attachment_path'),
            'status' => LeaveStatus::PENDING,
            'approved_by' => [],
        ]);

        $balance->increment('pending_days', $days);

        AuditLog::record('leave.requested', $leaveRequest, [
            'leave_type' => $leaveType->code,
            'days' => $days,
        ]);
        $this->webhook($leaveRequest->employee->tenant_id, 'leave.requested', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee->name,
            'leave_type' => $leaveType->code,
            'days' => $days,
        ]);

        $leaveRequest->load('employee', 'leaveType');

        return (new LeaveRequestResource($leaveRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function my(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = LeaveRequest::query()
            ->where('employee_id', $user->employee_id)
            ->with('leaveType');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $query->orderByDesc('created_at');

        return LeaveRequestResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function team(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('leave.viewTeam');

        $user = $request->user();

        $directReportIds = Employee::where('supervisor_id', $user->employee_id)
            ->pluck('id');

        $query = LeaveRequest::query()
            ->whereIn('employee_id', $directReportIds)
            ->with('employee', 'leaveType');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $query->orderByDesc('created_at');

        return LeaveRequestResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function balance(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $year = $request->integer('year', now()->year);

        $balances = \App\Models\LeaveBalance::query()
            ->where('employee_id', $user->employee_id)
            ->where('year', $year)
            ->with('leaveType')
            ->get();

        return LeaveBalanceResource::collection($balances);
    }

    public function employeeBalance(Request $request, Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('leave.viewAll');

        $year = $request->integer('year', now()->year);

        $balances = \App\Models\LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get();

        return LeaveBalanceResource::collection($balances);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('leave.approve');

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('leave.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $user = $request->user();
        $chain = $leaveRequest->approved_by ?? [];
        $chain[] = [
            'user_id' => $user->id,
            'role' => $user->role?->value,
            'at' => now()->toIso8601String(),
        ];

        $leaveRequest->update([
            'approved_by' => $chain,
            'status' => LeaveStatus::APPROVED,
        ]);

        $balance = \App\Models\LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', $leaveRequest->start_date->year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', (float) $leaveRequest->days);
            $balance->increment('used_days', (float) $leaveRequest->days);
        }

        AuditLog::record('leave.approved', $leaveRequest, [
            'approved_by' => $user->id,
        ]);
        $this->webhook($leaveRequest->employee->tenant_id, 'leave.approved', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee->name,
        ]);

        $leaveRequest->load('employee', 'leaveType');

        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('leave.approve');

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('leave.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $user = $request->user();

        $leaveRequest->update([
            'status' => LeaveStatus::REJECTED,
            'rejected_by' => $user->id,
            'rejected_reason' => $request->input('reason'),
        ]);

        $balance = \App\Models\LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', $leaveRequest->start_date->year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', (float) $leaveRequest->days);
        }

        AuditLog::record('leave.rejected', $leaveRequest, [
            'rejected_by' => $user->id,
            'reason' => $request->input('reason'),
        ]);
        $this->webhook($leaveRequest->employee->tenant_id, 'leave.rejected', [
            'public_id' => $leaveRequest->public_id,
            'employee_name' => $leaveRequest->employee->name,
            'reason' => $request->input('reason'),
        ]);

        $leaveRequest->load('employee', 'leaveType');

        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();

        if ($leaveRequest->employee_id !== $user->employee_id) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => __('leave.not_own_request'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        if ($leaveRequest->status !== LeaveStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('leave.not_pending'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $leaveRequest->update(['status' => LeaveStatus::CANCELLED]);

        $balance = \App\Models\LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->where('year', $leaveRequest->start_date->year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', (float) $leaveRequest->days);
        }

        AuditLog::record('leave.cancelled', $leaveRequest);

        $leaveRequest->load('employee', 'leaveType');

        return response()->json(new LeaveRequestResource($leaveRequest));
    }
}
