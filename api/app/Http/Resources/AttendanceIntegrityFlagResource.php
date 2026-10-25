<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceIntegrityFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceIntegrityFlag */
class AttendanceIntegrityFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->record;

        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'severity' => $this->severity,
            'punch' => $this->punch,
            'status' => $this->status,
            'details' => $this->details ?? [],
            'created_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'reviewer_name' => $this->reviewer?->name,
            'employee' => $this->employee === null ? null : [
                'public_id' => $this->employee->public_id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ],
            'record' => $record === null ? null : [
                'public_id' => $record->public_id,
                'date' => $record->date?->format('Y-m-d'),
                'check_in' => $record->check_in,
                'check_out' => $record->check_out,
                'source' => $record->source->value,
                'status' => $record->status->value,
                'shift_name' => $record->shift?->name,
                'latitude' => $record->latitude === null ? null : (float) $record->latitude,
                'longitude' => $record->longitude === null ? null : (float) $record->longitude,
                'risk_score' => $record->risk_score === null ? null : (int) $record->risk_score,
                'risk_reasons' => array_keys((array) ($record->risk_reasons ?? [])),
                'has_photo' => (bool) ($record->photo_path !== null || data_get($record->metadata, 'checkout_photo_path') !== null),
            ],
            'employee_findings_90_days' => AttendanceIntegrityFlag::query()
                ->where('employee_id', $this->employee_id)
                ->where('created_at', '>=', now()->subDays(90))
                ->count(),
        ];
    }
}
