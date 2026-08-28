<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            // An assignment carries either a fixed shift or a rotation. Both
            // keys are always present so a client can tell which kind this is
            // without inferring it from an absent field.
            'rotation' => $this->whenLoaded('rotation', fn () => new ShiftRotationResource($this->rotation)),
            'is_rotation' => $this->shift_rotation_id !== null,
            'anchor_date' => $this->anchor_date?->format('Y-m-d'),
            'assignable_type' => class_basename($this->assignable_type),
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'created_at' => $this->created_at,
        ];
    }
}
