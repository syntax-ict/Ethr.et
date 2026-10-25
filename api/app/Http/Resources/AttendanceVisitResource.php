<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceVisit */
class AttendanceVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee_public_id' => $this->relationLoaded('employee') ? $this->employee?->public_id : null,
            'employee_name' => $this->relationLoaded('employee') ? $this->employee?->name : null,
            'site_name' => $this->relationLoaded('site') ? $this->site?->name : null,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'accuracy' => $this->accuracy,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'note' => $this->note,
            'has_photo' => $this->photo_path !== null,
        ];
    }
}
