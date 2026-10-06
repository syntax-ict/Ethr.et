<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesPhotoUrls;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A node in the reporting (manager → direct reports) hierarchy served by
 * `GET /organization/reporting-tree`. Deliberately lean — the reporting chart
 * needs identity, a title, and an avatar, not the full employee record — with
 * descendants nested under `direct_reports`.
 *
 * @mixin Employee
 */
class EmployeeReportingNodeResource extends JsonResource
{
    use ExposesPhotoUrls;

    public function toArray(Request $request): array
    {
        $photoPath = $this->photo_path;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'employee_code' => $this->employee_code,
            'position' => $this->whenLoaded('position', fn () => $this->position?->title),
            'photo_url' => $this->photoUrl($photoPath),
            'photo_thumb_url' => $this->photoThumbUrl($photoPath),
            'direct_reports' => self::collection($this->whenLoaded('directReportsRecursive')),
        ];
    }
}
