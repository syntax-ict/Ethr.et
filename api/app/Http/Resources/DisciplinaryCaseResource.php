<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DisciplinaryCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DisciplinaryCase
 */
class DisciplinaryCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reference_number' => $this->reference_number,
            'category' => $this->category->value,
            'description' => $this->description,
            'incident_date' => $this->incident_date->toDateString(),
            'status' => $this->status->value,
            'reported_by' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy?->getAttribute('name')),
            'investigation_notes' => $this->investigation_notes ?? [],

            'decision' => $this->decision?->value,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->toDateString(),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->getAttribute('name')),

            'sanction_type' => $this->sanction_type?->value,
            'sanction_details' => $this->sanction_details,
            'sanction_effective_date' => $this->sanction_effective_date?->toDateString(),

            'appeal_status' => $this->appeal_status?->value,
            'appeal_grounds' => $this->appeal_grounds,
            'appeal_filed_at' => $this->appeal_filed_at?->toDateString(),
            'appeal_decision_notes' => $this->appeal_decision_notes,
            'appeal_decided_at' => $this->appeal_decided_at?->toDateString(),
            'appeal_decided_by' => $this->whenLoaded('appealDecidedBy', fn () => $this->appealDecidedBy?->getAttribute('name')),

            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
        ];
    }
}
