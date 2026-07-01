<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskCheckInRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class KioskCheckInController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function __invoke(KioskCheckInRequest $request): JsonResponse
    {
        $kioskToken = $request->header('X-Kiosk-Token') ?? $request->query('kiosk_token');

        if (! $kioskToken) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.token_required'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $session = KioskSession::where('token', $kioskToken)
            ->where('status', 'active')
            ->first();

        if (! $session) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $employee = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $session->tenant_id)
            ->where('employee_code', $request->validated('employee_code'))
            ->first();

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.employee_not_found'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $settings = AttendanceSetting::withoutGlobalScope('tenant')
            ->where('tenant_id', $session->tenant_id)
            ->first();

        if ($settings?->kiosk_pin_required) {
            $pin = $request->validated('pin');
            if (! $pin || ! $employee->kiosk_pin) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/validation',
                    'title' => 'PIN Required',
                    'status' => 422,
                    'detail' => __('kiosk.pin_required'),
                ], 422)->header('Content-Type', 'application/problem+json');
            }

            if (! Hash::check($pin, $employee->kiosk_pin)) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/unauthorized',
                    'title' => 'Invalid PIN',
                    'status' => 401,
                    'detail' => __('kiosk.invalid_pin'),
                ], 401)->header('Content-Type', 'application/problem+json');
            }
        }

        if ($settings && ! $settings->isMethodEnabled('kiosk')) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Method Disabled',
                'status' => 403,
                'detail' => __('attendance.method_disabled'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $session->tenant_id,
            source: AttendanceSource::KIOSK,
            type: $request->validated('type'),
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
            metadata: [
                'kiosk_session_id' => $session->id,
                'kiosk_branch_id' => $session->branch_id,
            ],
        ));

        $session->touchActivity();

        $result->record->load('employee', 'shift');

        $resource = new AttendanceRecordResource($result->record);
        $data = $resource->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;
        $data['employee_name'] = $employee->name;

        $isCheckOut = $request->validated('type') === 'check_out';
        $statusCode = $result->wasDuplicate || $isCheckOut ? 200 : 201;

        return response()->json($data, $statusCode);
    }
}
