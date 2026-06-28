<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'reason' => $this->reason,
            'effective_date' => $this->effective_date?->format('Y-m-d'),
            'approved_by' => new EmployeeSummaryResource($this->whenLoaded('approvedBy')),
            'created_at' => $this->created_at,
        ];
    }
}
