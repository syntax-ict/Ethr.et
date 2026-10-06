<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftRotationStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'day_offset' => $this->day_offset,
            // Null is a rest day, which is part of the pattern — the client
            // needs to tell it apart from a shift it failed to load, so the key
            // is always present rather than conditionally included.
            'shift' => $this->shift ? new ShiftResource($this->shift) : null,
            'is_rest_day' => $this->shift_id === null,
        ];
    }
}
