<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Holiday */
class HolidayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'date' => $this->date->format('Y-m-d'),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'branch_public_id' => $this->branch?->public_id,
            'ethiopian_calendar' => $this->ethiopian_calendar,
            'recurring' => $this->recurring,
            // Computed from the tabular Hijri calendar — HR should confirm
            // against the local sighting and adjust if needed.
            'is_estimated' => $this->is_estimated,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
