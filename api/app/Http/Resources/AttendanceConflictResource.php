<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceConflict;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceConflict */
class AttendanceConflictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee?->public_id,
            'record_a' => new AttendanceRecordResource($this->whenLoaded('recordA')),
            'record_a_public_id' => $this->recordA?->public_id,
            'record_b' => new AttendanceRecordResource($this->whenLoaded('recordB')),
            'record_b_public_id' => $this->recordB?->public_id,
            'conflict_type' => $this->conflict_type->value,
            'resolution' => $this->resolution->value,
            'resolved_by_public_id' => $this->resolvedByUser?->public_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
