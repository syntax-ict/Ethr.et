<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'username' => $this->username,
            'phone' => $this->phone,
            'role' => $this->role?->value,
            'status' => $this->status,
            'locale' => $this->locale,
            'mfa_enabled' => (bool) $this->mfa_enabled,
            'invited_at' => $this->invited_at,
            'activated_at' => $this->activated_at,
            'last_login_at' => $this->last_login_at,
            'custom_role' => $this->whenLoaded('customRole', fn () => $this->customRole ? [
                'public_id' => $this->customRole->public_id,
                'name' => $this->customRole->name,
            ] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'public_id' => $this->employee->public_id,
                'name' => $this->employee->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
