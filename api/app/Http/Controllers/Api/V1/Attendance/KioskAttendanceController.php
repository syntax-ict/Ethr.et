<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\KioskAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class KioskAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function store(KioskAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = Employee::where('employee_code', $request->validated('employee_code'))->first();

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.employee_not_found'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        // Punching for someone else is manual attendance and takes the same
        // ability. This gated on attendance.checkIn alone — granted to every
        // role, so that people can punch themselves — and then recorded for
        // whatever employee_code the body named: any employee could clock a
        // colleague in or out, straight into payroll. The shared kiosk
        // terminal is unaffected: it authenticates with a kiosk token on the
        // /kiosk routes, not here.
        $user = $request->user();
        if ($employee->id !== $user->employee_id && ! $user->hasPermission('attendance.manage')) {
            abort(403);
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::KIOSK,
            type: $request->validated('type'),
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
        ));

        $result->record->load('employee', 'shift');

        $resource = new AttendanceRecordResource($result->record);
        $data = $resource->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;

        $isCheckOut = $request->validated('type') === 'check_out';
        $statusCode = $result->wasDuplicate || $isCheckOut ? 200 : 201;

        return response()->json($data, $statusCode);
    }
}
