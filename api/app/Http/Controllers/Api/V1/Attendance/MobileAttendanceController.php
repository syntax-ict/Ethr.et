<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\MobileCheckInRequest;
use App\Http\Requests\Attendance\MobileCheckOutRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MobileAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function checkIn(MobileCheckInRequest $request): JsonResponse
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
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        try {
            $result = $this->engine->record(new AttendanceInput(
                employeeId: $employee->id,
                tenantId: $employee->tenant_id,
                source: AttendanceSource::MOBILE,
                type: 'check_in',
                idempotencyKey: $request->validated('idempotency_key'),
                latitude: $request->validated('latitude'),
                longitude: $request->validated('longitude'),
                photoPath: $request->validated('photo_path'),
                ipAddress: $request->ip(),
            ));
        } catch (\RuntimeException $e) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => $e->getMessage(),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $result->record->load('employee', 'shift');

        $resource = new AttendanceRecordResource($result->record);
        $data = $resource->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;

        return response()->json($data, $result->wasDuplicate ? 200 : 201);
    }

    public function checkOut(MobileCheckOutRequest $request): JsonResponse
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
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::MOBILE,
            type: 'check_out',
            idempotencyKey: $request->validated('idempotency_key'),
            latitude: $request->validated('latitude'),
            longitude: $request->validated('longitude'),
            ipAddress: $request->ip(),
        ));

        $result->record->load('employee', 'shift');

        return (new AttendanceRecordResource($result->record))
            ->response()
            ->setStatusCode(200);
    }
}
