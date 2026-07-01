<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KioskSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'branch' => $this->whenLoaded('branch', fn () => [
                'public_id' => $this->branch->public_id,
                'name' => $this->branch->name,
            ]),
            'device_identifier' => $this->device_identifier,
            'status' => $this->status,
            'token' => $this->when($this->wasRecentlyCreated, $this->token),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
