<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where a request stands in its approval chain, and who did what. Null on a
 * request submitted before the approval engine, which is decided in one step.
 *
 * @mixin ApprovalInstance
 */
class ApprovalSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'step' => $this->current_position,
            'steps' => $this->stepCount(),
            'status' => $this->status,
            'history' => ApprovalActionResource::collection($this->whenLoaded('actions')),
        ];
    }
}
