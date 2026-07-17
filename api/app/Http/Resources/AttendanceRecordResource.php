<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee?->public_id,
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'date' => $this->date?->format('Y-m-d'),
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'confidence_score' => $this->confidence_score,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_verified' => $this->geofence_verified,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'worked_minutes' => $this->workedMinutes(),
            'overtime_minutes' => $this->overtimeMinutes(),
            'conflict' => isset($this->metadata['conflict_action']) ? [
                'action' => $this->metadata['conflict_action'],
                'with_record_public_id' => $this->metadata['conflict_with'] ?? $this->metadata['resolved_with'] ?? null,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
