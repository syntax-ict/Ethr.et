<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One day of a person's own attendance, as kiosk self-service shows it.
 *
 * @mixin AttendanceRecord
 */
class KioskAttendanceDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date?->format('Y-m-d'),
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'status' => $this->status->value,
            'worked_minutes' => $this->workedMinutes(),
        ];
    }
}
