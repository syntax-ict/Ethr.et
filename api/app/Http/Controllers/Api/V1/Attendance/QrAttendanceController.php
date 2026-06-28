<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\QrAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Branch;
use App\Models\Shift;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QrAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly QrCodeService $qrService,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $request->validate([
            'branch_public_id' => ['required', 'string', 'exists:branches,public_id'],
            'shift_public_id' => ['nullable', 'string', 'exists:shifts,public_id'],
            'expiry_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ]);

        $branch = Branch::where('public_id', $request->input('branch_public_id'))->firstOrFail();
        $shift = $request->filled('shift_public_id')
            ? Shift::where('public_id', $request->input('shift_public_id'))->first()
            : null;

        $result = $this->qrService->generate(
            $branch,
            $shift,
            $request->integer('expiry_minutes', 30)
        );

        return response()->json($result);
    }

    public function scan(QrAttendanceRequest $request): JsonResponse
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

        $tenant = app(CurrentTenant::class)->get();
        $payload = $this->qrService->validate($request->validated('qr_token'), $tenant->id);

        if (! $payload) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-qr',
                'title' => 'Invalid QR Code',
                'status' => 422,
                'detail' => __('attendance.qr_invalid_or_expired'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::QR,
            type: $request->validated('type'),
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
            metadata: [
                'qr_branch_id' => $payload['branch_id'],
                'qr_shift_id' => $payload['shift_id'] ?? null,
            ],
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
