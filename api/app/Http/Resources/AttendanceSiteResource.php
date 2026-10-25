<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceSite;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceSite */
class AttendanceSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : collect();

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'branch_public_id' => $this->relationLoaded('branch') ? $this->branch?->public_id : null,
            'branch_name' => $this->relationLoaded('branch') ? $this->branch?->name : null,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'radius_meters' => (int) $this->radius_meters,
            'is_active' => (bool) $this->is_active,
            'employee_public_ids' => Employee::query()
                ->whereIn('id', $assignments->where('assignable_type', Employee::class)->pluck('assignable_id'))
                ->pluck('public_id')
                ->values()
                ->all(),
            'department_public_ids' => Department::query()
                ->whereIn('id', $assignments->where('assignable_type', Department::class)->pluck('assignable_id'))
                ->pluck('public_id')
                ->values()
                ->all(),
        ];
    }
}
