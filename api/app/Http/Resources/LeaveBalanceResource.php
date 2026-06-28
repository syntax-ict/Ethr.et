<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'leave_type' => new LeaveTypeResource($this->whenLoaded('leaveType')),
            'leave_type_public_id' => $this->leaveType?->public_id,
            'year' => $this->year,
            'entitled_days' => (float) $this->entitled_days,
            'used_days' => (float) $this->used_days,
            'carried_days' => (float) $this->carried_days,
            'pending_days' => (float) $this->pending_days,
            'remaining_days' => $this->remainingDays(),
        ];
    }
}
