<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function checkIn(CheckInRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.no_employee_linked'),
            ], 404);
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::WEB,
            type: 'check_in',
            idempotencyKey: $request->validated('idempotency_key'),
            latitude: $request->validated('latitude'),
            longitude: $request->validated('longitude'),
            ipAddress: $request->ip(),
        ));

        $result->record->load('employee', 'shift');

        $resource = new AttendanceRecordResource($result->record);
        $data = $resource->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;

        return response()->json($data, $result->wasDuplicate ? 200 : 201);
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.no_employee_linked'),
            ], 404);
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::WEB,
            type: 'check_out',
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
        ));

        $result->record->load('employee', 'shift');

        return (new AttendanceRecordResource($result->record))
            ->response()
            ->setStatusCode(200);
    }

    public function my(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewOwn');

        $user = $request->user();
        $employee = $user->employee;

        $query = AttendanceRecord::query()
            ->where('employee_id', $employee?->id)
            ->with('employee', 'shift');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        $query->orderByDesc('date')->orderByDesc('check_in');

        return AttendanceRecordResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function team(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewTeam');

        $user = $request->user();

        $query = AttendanceRecord::query()
            ->with('employee', 'shift')
            ->whereHas('employee', function ($q) use ($user) {
                $user->scopeAccessibleEmployees($q);
            });

        if ($request->filled('date')) {
            $query->where('date', $request->input('date'));
        } else {
            $query->where('date', now()->format('Y-m-d'));
        }

        $query->orderByDesc('check_in');

        return AttendanceRecordResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewAll');

        $user = $request->user();
        $query = AttendanceRecord::query()->with('employee', 'shift');

        $query->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q));

        if ($request->filled('filter.employee_public_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('public_id', $request->input('filter.employee_public_id'));
            });
        }

        if ($request->filled('filter.department_public_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->whereHas('department', function ($dq) use ($request) {
                    $dq->where('public_id', $request->input('filter.department_public_id'));
                });
            });
        }

        if ($request->filled('filter.branch_public_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->whereHas('branch', function ($bq) use ($request) {
                    $bq->where('public_id', $request->input('filter.branch_public_id'));
                });
            });
        }

        if ($request->filled('filter.date_from')) {
            $query->where('date', '>=', $request->input('filter.date_from'));
        }

        if ($request->filled('filter.date_to')) {
            $query->where('date', '<=', $request->input('filter.date_to'));
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.source')) {
            $query->where('source', $request->input('filter.source'));
        }

        $query->orderByDesc('date')->orderByDesc('check_in');

        return AttendanceRecordResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function today(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewAll');

        $today = now()->format('Y-m-d');
        $user = $request->user();

        $records = AttendanceRecord::query()
            ->where('date', $today)
            ->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q))
            ->get();

        $totalQuery = Employee::query();
        $user->scopeAccessibleEmployees($totalQuery);
        $totalEmployees = $totalQuery->count();

        return response()->json([
            'date' => $today,
            'total_employees' => $totalEmployees,
            'present' => $records->whereIn('status', [AttendanceStatus::PRESENT, AttendanceStatus::LATE, AttendanceStatus::EARLY_LEAVE])->count(),
            'absent' => $totalEmployees - $records->whereNotNull('check_in')->unique('employee_id')->count(),
            'late' => $records->where('status', AttendanceStatus::LATE)->count(),
            'early_leave' => $records->where('status', AttendanceStatus::EARLY_LEAVE)->count(),
            'on_leave' => $records->where('status', AttendanceStatus::ON_LEAVE)->count(),
        ]);
    }

    public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        Gate::authorize('attendance.view');

        $attendanceRecord->load('employee', 'shift');

        return new AttendanceRecordResource($attendanceRecord);
    }
}
