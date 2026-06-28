<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'parent' => new DepartmentResource($this->whenLoaded('parent')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'children' => DepartmentResource::collection($this->whenLoaded('children')),
            'children_recursive' => DepartmentResource::collection($this->whenLoaded('childrenRecursive')),
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
