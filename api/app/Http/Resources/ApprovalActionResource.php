<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a request's approval history: an approval or rejection, or
 * `fallback` when a step named no one who could decide it and passed to
 * everyone holding the permission.
 *
 * @mixin ApprovalAction
 */
class ApprovalActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'step' => $this->position,
            'action' => $this->action,
            'actor_name' => $this->actor?->name,
            'comment' => $this->comment,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
