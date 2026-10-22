<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OvertimeRequest */
class OvertimeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeSummaryResource($this->whenLoaded('employee')),
            'date' => $this->date->format('Y-m-d'),
            'planned_minutes' => $this->planned_minutes,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'approval' => new ApprovalSummaryResource($this->whenLoaded('approvalInstance')),
            'created_at' => $this->created_at,
        ];
    }
}
