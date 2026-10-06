<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveTypeRequest;
use App\Http\Requests\Leave\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\AuditLog;
use App\Models\LeaveType;
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

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        Gate::authorize('leave.manageTypes');

        AuditLog::record('leave_type.deleted', $leaveType);
        $leaveType->delete();

        return response()->json(null, 204);
    }
}
