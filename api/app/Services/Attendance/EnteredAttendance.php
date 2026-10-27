<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * A day of attendance a person typed rather than a clock recorded: HR's
 * manual entry, and an approved missed-punch claim. Both go through the
 * engine at the entered moment, so the shift is matched, lateness is rated
 * and clashing punches are found on that day, not on the day of typing.
 *
 * Moved here from `ManualAttendanceController` on 2026-10-27 so a missed-punch
 * claim creates its record by the very same steps instead of a copy of them.
 */
final class EnteredAttendance
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    /**
     * @param  string  $date  the work date, Y-m-d
     * @param  array<string, mixed>  $metadata
     */
    public function enter(
        Employee $employee,
        string $date,
        CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
        array $metadata,
        ?string $idempotencyKey = null,
        ?string $ipAddress = null,
    ): AttendanceResult {
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
            idempotencyKey: $idempotencyKey,
            ipAddress: $ipAddress,
            metadata: $metadata,
            occurredAt: $checkIn->toIso8601String(),
        ));

        if ($result->wasDuplicate) {
            return $result;
        }

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

        // The engine rated the check-in; a typed check-out is rated here, by
        // the same rule a live one is (attendance QA A5).
        $this->engine->reclassify($result->record);

        return $result;
    }
}
