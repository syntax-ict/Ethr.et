<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Resources\AttendancePunchResource;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\EnteredAttendance;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollPeriodLock;
use App\Support\TenantTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ManualAttendanceController extends Controller
{
    public function __construct(
        private readonly EnteredAttendance $entered,
    ) {}

    public function store(ManualAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $employee = Employee::where('public_id', $request->validated('employee_public_id'))->firstOrFail();

        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $date = $request->validated('date');
        app(PayrollPeriodLock::class)->assertOpen($employee->id, $date);
        $checkIn = TenantTime::wallClockToUtc($date, $request->validated('check_in'), $zone);
        $checkOut = $request->validated('check_out')
            ? TenantTime::wallClockToUtc($date, $request->validated('check_out'), $zone)
            : null;

        // The times are what HR read off a sheet or a clock in the tenant's
        // own timezone. Written as "{date} {H:i}:00" they were stored as UTC
        // wall-clock, so 09:00 in Addis Ababa came back as 12:00.
        $result = $this->entered->enter(
            $employee,
            $date,
            $checkIn,
            $checkOut,
            [
                'reason' => $request->validated('reason'),
                'entered_by' => $request->user()->id,
                'manual_date' => $request->validated('date'),
                'manual_check_in' => $request->validated('check_in'),
                'manual_check_out' => $request->validated('check_out'),
            ],
            $request->validated('idempotency_key'),
            $request->ip(),
        );

        if ($result->wasDuplicate) {
            $result->record->load('employee', 'shift');

            return (new AttendancePunchResource($result->record, true))->response()->setStatusCode(200);
        }

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
