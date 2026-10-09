<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Resources\AttendancePunchResource;
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

        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $date = $request->validated('date');
        $checkIn = TenantTime::wallClockToUtc($date, $request->validated('check_in'), $zone);
        $checkOut = $request->validated('check_out')
            ? TenantTime::wallClockToUtc($date, $request->validated('check_out'), $zone)
            : null;

        // The engine is given the entered moment, not left to use "now". It
        // matches the shift, rates lateness and looks for clashing punches at
        // that moment. Until 2026-10-09 all three ran at the moment HR pressed
        // Save, and the record was moved to the entered date afterwards. A
        // backdated entry was compared with today's punches and could be
        // merged into one, and a real clash on the entered day went unseen.
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
            occurredAt: $checkIn->toIso8601String(),
        ));

        if ($result->wasDuplicate) {
            $result->record->load('employee', 'shift');

            return (new AttendancePunchResource($result->record, true))->response()->setStatusCode(200);
        }

        // The times are what HR read off a sheet or a clock in the tenant's
        // own timezone. Written as "{date} {H:i}:00" they were stored as UTC
        // wall-clock, so 09:00 in Addis Ababa came back as 12:00.
        //
        // When the entry landed within minutes of a punch already on record,
        // the engine merged the two and kept the earlier check-in. Only a
        // later check-out is added then, so the merge is not undone and an
        // existing check-out is never erased.
        $merged = in_array($result->record->metadata['conflict_action'] ?? null, ['merged', 'deduped'], true);

        if ($merged) {
            if ($checkOut !== null && ($result->record->check_out === null || $checkOut->gt($result->record->check_out))) {
                $result->record->update(['check_out' => $checkOut]);
            }
        } else {
            $result->record->update([
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ]);
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
