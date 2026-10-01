<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ManualAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function store(ManualAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $employee = Employee::where('public_id', $request->validated('employee_public_id'))->firstOrFail();

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $employee->tenant_id,
            source: AttendanceSource::MANUAL,
            type: 'check_in',
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
            metadata: [
                'reason' => $request->validated('reason'),
                'entered_by' => $request->user()->id,
                'manual_date' => $request->validated('date'),
                'manual_check_in' => $request->validated('check_in'),
                'manual_check_out' => $request->validated('check_out'),
            ],
        ));

        if ($result->wasDuplicate) {
            $result->record->load('employee', 'shift');

            $data = (new AttendanceRecordResource($result->record))->resolve();
            $data['was_duplicate'] = true;

            return response()->json($data, 200);
        }

        // The times are what HR read off a sheet or a clock in the tenant's
        // own timezone. Written as "{date} {H:i}:00" they were stored as UTC
        // wall-clock, so 09:00 in Addis Ababa came back as 12:00.
        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $date = $request->validated('date');
        $checkIn = TenantTime::wallClockToUtc($date, $request->validated('check_in'), $zone);
        $checkOut = $request->validated('check_out')
            ? TenantTime::wallClockToUtc($date, $request->validated('check_out'), $zone)
            : null;

        $result->record->update([
            'date' => $date,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        AuditLog::record('attendance.manual_entry', $result->record, [
            'employee_public_id' => $employee->public_id,
            'reason' => $request->validated('reason'),
            'entered_by_user_id' => $request->user()->id,
        ]);

        $result->record->load('employee', 'shift');

        return (new AttendanceRecordResource($result->record))
            ->response()
            ->setStatusCode(201);
    }
}
