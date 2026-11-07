<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use App\Services\Attendance\DayContext;
use App\Services\Attendance\PunchOutcome;
use Illuminate\Http\Request;

/**
 * What a punch endpoint answers: the record, plus whether this request was a
 * replay of one already processed (same idempotency key), and — for the
 * shared kiosk, where nobody is signed in — whose punch it was.
 *
 * The endpoints used to `resolve()` an AttendanceRecordResource and append
 * keys to the array, which the API contract cannot follow; it published the
 * whole body as a string.
 *
 * `punch` says which punch it was. It is what a caller that sent `auto` reads
 * to greet the person: the shared kiosk does not know before the code is
 * typed whether they are arriving or leaving.
 *
 * @mixin AttendanceRecord
 */
class AttendancePunchResource extends AttendanceRecordResource
{
    public function __construct(
        AttendanceRecord $record,
        private readonly bool $wasDuplicate,
        private readonly ?string $employeeName = null,
        private readonly string $punch = 'check_in',
        private readonly ?PunchOutcome $outcome = null,
    ) {
        parent::__construct($record);
    }

    public function toArray(Request $request): array
    {
        // What the day is for this person (a holiday and its name, a rest day,
        // leave), so every way of punching can say it: web, phone, QR, kiosk.
        $employee = $this->resource->employee;
        $day = $employee === null ? null : DayContext::of($employee, $this->resource->date ?? now());

        return array_merge(parent::toArray($request), [
            'was_duplicate' => $this->wasDuplicate,
            'punch' => $this->punch,
            'employee_name' => $this->when($this->employeeName !== null, $this->employeeName),
            'day' => $day,
            'outcome' => PunchOutcome::describe($this->outcome),
        ]);
    }
}
