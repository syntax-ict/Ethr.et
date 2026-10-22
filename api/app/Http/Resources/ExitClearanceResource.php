<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExitClearance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exit clearance with its lines, and how many of them are done.
 *
 * @mixin ExitClearance
 */
class ExitClearanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeSummaryResource($this->whenLoaded('employee')),
            'exit_status' => $this->exit_status,
            'leaving_date' => $this->leaving_date?->format('Y-m-d'),
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'done_count' => (int) ($this->done_count ?? 0),
            'item_count' => (int) ($this->item_count ?? 0),
            'items' => ExitClearanceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
