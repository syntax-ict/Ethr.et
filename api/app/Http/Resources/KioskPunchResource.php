<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the shared kiosk is told after a punch: which punch it was, when, and
 * who it was for (KioskPersonResource). The signed-in punch endpoints answer
 * with AttendancePunchResource, which carries the whole employee record; the
 * kiosk did too until 2026-11-01.
 *
 * `punch` is what a terminal that sent `auto` reads to greet the person.
 *
 * @mixin AttendanceRecord
 */
class KioskPunchResource extends JsonResource
{
    public function __construct(
        AttendanceRecord $record,
        private readonly Employee $employee,
        private readonly bool $wasDuplicate,
        private readonly string $punch,
    ) {
        parent::__construct($record);
    }

    public function toArray(Request $request): array
    {
        $this->employee->loadMissing('position');

        return [
            'public_id' => $this->public_id,
            'date' => $this->date?->format('Y-m-d'),
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'source' => $this->source->value,
            'status' => $this->status->value,
            'punch' => $this->punch,
            'was_duplicate' => $this->wasDuplicate,
            'employee_name' => $this->employee->name,
            'employee' => new KioskPersonResource($this->employee),
        ];
    }
}
