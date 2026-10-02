<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomRole */
class CustomRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($permission) => (string) $permission->getAttribute('name'))->values()->all()),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
