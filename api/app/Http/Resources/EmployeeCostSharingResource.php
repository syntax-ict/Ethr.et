<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmployeeCostSharing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeCostSharing
 */
class EmployeeCostSharingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Derived rather than stored: a stored copy would drift the moment an
        // obligation is cancelled with a balance outstanding. The cast is what
        // tells the API contract it is an integer; it typed the bare
        // subtraction as a string.
        $repaidCents = (int) ($this->total_obligation_cents - $this->outstanding_cents);

        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee->public_id,
            'total_obligation_cents' => $this->total_obligation_cents,
            'outstanding_cents' => $this->outstanding_cents,
            'repaid_cents' => $repaidCents,
            'deduction_rate_percent' => (float) $this->deduction_rate_percent,
            'status' => $this->status,
            'started_on' => $this->started_on->format('Y-m-d'),
            'completed_at' => $this->completed_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
