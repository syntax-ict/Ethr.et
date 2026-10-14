<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\Employee;

/**
 * What an `attendance.*` webhook says about a record: who, which day, the
 * punches, the status and how it was recorded.
 *
 * Deliberately not the record itself. The row also holds the punch's GPS
 * position, the selfie's path, the device's IP address and the integrity
 * score, and none of that leaves ETHR: a payload names people by public id
 * and name, as the employee, leave and payroll events do.
 */
final class AttendanceWebhookPayload
{
    /** @return array<string, string|null> */
    public static function of(AttendanceRecord $record, Employee $employee): array
    {
        return [
            'public_id' => $record->public_id,
            'employee_public_id' => $employee->public_id,
            'employee_name' => $employee->name,
            'date' => $record->date?->format('Y-m-d'),
            'check_in' => $record->check_in?->toIso8601String(),
            'check_out' => $record->check_out?->toIso8601String(),
            'status' => $record->status->value,
            'source' => $record->source->value,
        ];
    }
}
