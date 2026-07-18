<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'type' => $this->type,
            'status' => $this->status->value,
            'employee_count' => $this->employees_count,
            'trial_ends_at' => $this->trial_ends_at,
            'created_at' => $this->created_at,
        ];
    }
}
