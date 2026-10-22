<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExitClearanceItem;
use App\Models\User;
use App\Services\Offboarding\ExitClearanceService;
use App\Support\ExitClearanceTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an exit clearance. `can_tick` is for the reader: HR may tick
 * any line, finance its own, the leaver's supervisor theirs. It needs the
 * line's `clearance.employee` loaded and is false without it.
 *
 * @mixin ExitClearanceItem
 */
class ExitClearanceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'key' => $this->key,
            'label' => ExitClearanceTemplate::label($this->key, null, $this->label),
            'owner' => $this->owner,
            'kind' => $this->kind,
            'done' => $this->done_at !== null,
            'done_at' => $this->done_at?->toIso8601String(),
            'done_by' => $this->whenLoaded('doneBy', fn () => $this->doneBy?->name),
            'note' => $this->note,
            'can_tick' => $this->canTick($request),
        ];
    }

    private function canTick(Request $request): bool
    {
        $user = $request->user();
        $employee = $this->relationLoaded('clearance') ? $this->clearance?->employee : null;

        return $user instanceof User && $employee !== null
            && app(ExitClearanceService::class)->canTick($user, $this->resource, $employee);
    }
}
