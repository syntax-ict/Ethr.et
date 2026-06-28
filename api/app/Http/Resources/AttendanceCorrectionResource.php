<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'attendance_record' => new AttendanceRecordResource($this->whenLoaded('attendanceRecord')),
            'attendance_record_public_id' => $this->attendanceRecord?->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee?->public_id,
            'reason' => $this->reason,
            'proposed_check_in' => $this->proposed_check_in?->toIso8601String(),
            'proposed_check_out' => $this->proposed_check_out?->toIso8601String(),
            'status' => $this->status?->value,
            'approval_chain' => $this->approval_chain,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
