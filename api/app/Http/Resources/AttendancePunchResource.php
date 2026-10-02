<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
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
 * @mixin AttendanceRecord
 */
class AttendancePunchResource extends AttendanceRecordResource
{
    public function __construct(
        AttendanceRecord $record,
        private readonly bool $wasDuplicate,
        private readonly ?string $employeeName = null,
    ) {
        parent::__construct($record);
    }

    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'was_duplicate' => $this->wasDuplicate,
            'employee_name' => $this->when($this->employeeName !== null, $this->employeeName),
        ]);
    }
}
