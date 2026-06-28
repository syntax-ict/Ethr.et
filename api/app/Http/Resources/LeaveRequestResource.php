<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee?->public_id,
            'leave_type' => new LeaveTypeResource($this->whenLoaded('leaveType')),
            'leave_type_public_id' => $this->leaveType?->public_id,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'days' => (float) $this->days,
            'reason' => $this->reason,
            'attachment_path' => $this->attachment_path,
            'status' => $this->status?->value,
            'approved_by' => $this->approved_by,
            'rejected_reason' => $this->rejected_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
