<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalWorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalWorkflowStep */
class ApprovalWorkflowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'approver_kind' => $this->approver_kind->value,
            'approver_ref' => $this->approver_ref,
            'sla_hours' => $this->sla_hours,
        ];
    }
}
