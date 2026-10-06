<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ShiftAssignment;
use App\Support\ShiftAssignables;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An assignment carries either a fixed shift or a rotation. `shift` and
 * `rotation` are both always present, one of them null, so a client can tell
 * which kind it is without inferring it from an absent field. They used to be
 * `whenLoaded`, and `schedule()` loaded only `shift` — a rotation assignment
 * arrived with neither key and every page showed "Unknown Shift".
 *
 * Callers load `ShiftAssignment::resourceRelations()`; `preventLazyLoading` is
 * on outside production, so a caller that forgets fails its tests rather than
 * issuing a query per row.
 *
 * @mixin ShiftAssignment
 */
class ShiftAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The assignee is reported in the request's vocabulary
        // (`employee` / `department` / `branch`), so a client can send it
        // straight back. `name` and `public_id` are null only when the target
        // row no longer exists at all.
        $assignable = $this->assignable;

        return [
            'public_id' => $this->public_id,
            'shift' => $this->shift ? new ShiftResource($this->shift) : null,
            'rotation' => $this->rotation ? new ShiftRotationResource($this->rotation) : null,
            'is_rotation' => $this->shift_rotation_id !== null,
            'anchor_date' => $this->anchor_date?->format('Y-m-d'),
            'assignable_type' => class_basename($this->assignable_type),
            'assignee' => [
                'type' => ShiftAssignables::typeFor($this->assignable_type),
                'public_id' => $this->assigneePublicId($assignable),
                'name' => $this->assigneeName($assignable),
            ],
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'created_at' => $this->created_at,
        ];
    }

    private function assigneePublicId(mixed $assignable): ?string
    {
        return is_object($assignable) && is_string($assignable->public_id ?? null) ? $assignable->public_id : null;
    }

    private function assigneeName(mixed $assignable): ?string
    {
        return is_object($assignable) && is_string($assignable->name ?? null) ? $assignable->name : null;
    }
}
