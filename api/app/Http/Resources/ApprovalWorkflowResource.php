<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalWorkflow */
class ApprovalWorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'request_type' => $this->request_type,
            'name' => $this->name,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'conditions' => $this->conditions,
            'steps' => ApprovalWorkflowStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
