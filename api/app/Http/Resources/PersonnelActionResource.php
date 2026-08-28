<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonnelAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonnelAction
 */
class PersonnelActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type->value,
            'is_temporary' => $this->is_temporary,
            'effective_date' => $this->effective_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'reference_number' => $this->reference_number,
            'reason' => $this->reason,
            'remarks' => $this->remarks,
            'changes' => $this->changes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->getAttribute('name')),
            'created_at' => $this->created_at,
        ];
    }
}
