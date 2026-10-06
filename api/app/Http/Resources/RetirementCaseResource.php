<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RetirementCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RetirementCase
 */
class RetirementCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'retirement_type' => $this->retirement_type->value,
            'status' => $this->status->value,
            'service_years' => (float) $this->service_years,
            'eligible_retirement_date' => $this->eligible_retirement_date?->toDateString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'initiated_by' => $this->whenLoaded('initiatedBy', fn () => $this->initiatedBy?->getAttribute('name')),

            'decision' => $this->decision?->value,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->getAttribute('name')),

            'finalized_at' => $this->finalized_at?->toIso8601String(),

            'created_at' => $this->created_at,
        ];
    }
}
