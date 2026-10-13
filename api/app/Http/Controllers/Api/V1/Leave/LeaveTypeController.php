<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveTypeRequest;
use App\Http\Requests\Leave\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\CopyName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('leave.viewTypes');

        $query = LeaveType::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('sort_order');

        return LeaveTypeResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        Gate::authorize('leave.manageTypes');

        $leaveType = LeaveType::create($request->validated());

        AuditLog::record('leave_type.created', $leaveType);

        return (new LeaveTypeResource($leaveType))
            ->response()
            ->setStatusCode(201);
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        Gate::authorize('leave.viewTypes');

        return new LeaveTypeResource($leaveType);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        Gate::authorize('leave.manageTypes');

        $leaveType->update($request->validated());

        AuditLog::record('leave_type.updated', $leaveType);

        return new LeaveTypeResource($leaveType);
    }

    /**
     * A copy starts switched off: an active leave type is offered to every
     * employee the moment it exists, and a copy is something to adjust first.
     */
    public function duplicate(LeaveType $leaveType): JsonResponse
    {
        Gate::authorize('leave.manageTypes');

        $copy = $leaveType->replicate(['public_id']);
        $copy->code = CopyName::code(
            $leaveType->code,
            static fn (string $code): bool => LeaveType::withTrashed()->where('code', $code)->exists(),
        );
        $copy->name = CopyName::name(
            $leaveType->name,
            static fn (string $name): bool => LeaveType::withTrashed()->where('name', $name)->exists(),
            100,
        );
        $copy->is_active = false;
        $copy->save();

        AuditLog::record('leave_type.duplicated', $copy, ['source' => $leaveType->public_id]);

        return (new LeaveTypeResource($copy))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        Gate::authorize('leave.manageTypes');

        // A leave type that requests or balances point at is part of the
        // record: soft-deleting it dropped it from every list while those rows
        // still named it, so a past request showed no type and a balance no
        // longer added up. Refused, and deactivating is the way to retire it.
        $requests = LeaveRequest::query()->where('leave_type_id', $leaveType->id)->count();
        $balances = LeaveBalance::query()->where('leave_type_id', $leaveType->id)->count();

        if ($requests > 0 || $balances > 0) {
            $reasons = [];
            if ($requests > 0) {
                $reasons[] = $requests === 1 ? '1 leave request uses it' : "{$requests} leave requests use it";
            }
            if ($balances > 0) {
                $reasons[] = $balances === 1 ? '1 leave balance is kept for it' : "{$balances} leave balances are kept for it";
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/leave-type-in-use',
                'title' => 'Leave Type In Use',
                'status' => 409,
                'detail' => 'This leave type cannot be deleted: '.implode(', and ', $reasons)
                    .'. Mark it inactive instead; employees will no longer be offered it and its history stays intact.',
            ], 409)->header('Content-Type', 'application/problem+json');
        }

        AuditLog::record('leave_type.deleted', $leaveType);
        $leaveType->delete();

        return response()->json(null, 204);
    }
}
