<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftRotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'description' => $this->description,
            'cycle_days' => $this->cycle_days,
            'is_active' => $this->is_active,
            'steps' => ShiftRotationStepResource::collection($this->whenLoaded('steps')),
            'assignments_count' => $this->whenCounted('assignments'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
