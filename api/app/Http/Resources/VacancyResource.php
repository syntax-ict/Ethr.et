<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Vacancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vacancy */
class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'employment_type' => $this->employment_type,
            'location' => $this->location,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'positions' => $this->positions,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'closes_on' => $this->closes_on?->format('Y-m-d'),
            'apply_email' => $this->apply_email,
            'apply_url' => $this->apply_url,
            'department' => $this->department === null ? null : [
                'public_id' => $this->department->public_id,
                'name' => $this->department->name,
            ],
            'branch' => $this->branch === null ? null : [
                'public_id' => $this->branch->public_id,
                'name' => $this->branch->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
