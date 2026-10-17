<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use App\Services\Attendance\PhotoRetention;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An attendance record's selfies, for review: which punches have one, how long
 * they are kept, whether the review is open and whether they are on hold.
 *
 * @mixin AttendanceRecord
 */
class AttendancePhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $days = (int) $request->attributes->get('photo_retention_days', PhotoRetention::DEFAULT_RETENTION_DAYS);
        $photos = array_keys($this->resource->photoPaths());
        $held = $this->photo_held_at !== null;

        return [
            'public_id' => $this->public_id,
            'date' => $this->date?->format('Y-m-d'),
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'employee' => [
                'public_id' => $this->employee?->public_id,
                'name' => $this->employee?->name,
                'employee_code' => $this->employee?->employee_code,
            ],
            'has_check_in_photo' => in_array('check_in', $photos, true),
            'has_check_out_photo' => in_array('check_out', $photos, true),
            'keep_until' => $this->iso(PhotoRetention::keepUntil($this->resource, $days)),
            'review_started_at' => $this->photo_review_started_at?->toIso8601String(),
            'delete_after' => $held ? null : $this->iso(PhotoRetention::deleteAfter($this->resource)),
            'held' => $held,
            'held_at' => $this->photo_held_at?->toIso8601String(),
            'hold_reason' => $this->photo_hold_reason,
            'held_by' => $this->photoHolder->employee->name ?? $this->photoHolder?->email,
        ];
    }

    /** Through a typed return, so the API contract reads `string|null`. */
    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->toIso8601String();
    }
}
