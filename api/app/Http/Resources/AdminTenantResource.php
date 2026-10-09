<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class AdminTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'custom_domain' => $this->custom_domain,
            'custom_domain_status' => $this->custom_domain === null ? null : ($this->hasVerifiedCustomDomain() ? 'verified' : 'pending'),
            'type' => $this->type,
            'status' => $this->status->value,
            'employee_count' => (int) $this->employees_count,
            'trial_ends_at' => $this->trial_ends_at,
            'created_at' => $this->created_at,
        ];
    }
}
