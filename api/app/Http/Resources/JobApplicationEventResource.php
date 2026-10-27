<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JobApplicationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobApplicationEvent */
class JobApplicationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type,
            'from_stage' => $this->from_stage,
            'to_stage' => $this->to_stage,
            'note' => $this->note,
            'score' => $this->score,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->getAttribute('name')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
