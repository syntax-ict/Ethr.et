<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PensionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PensionRule */
class PensionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'owner' => $this->tenant_id === null ? 'platform' : 'organisation',
            'scheme' => $this->scheme,
            'employee_rate' => (float) $this->employee_rate,
            'employer_rate' => (float) $this->employer_rate,
            'ceiling_cents' => $this->ceiling_cents,
            'floor_cents' => $this->floor_cents,
            'base' => $this->base,
            'exempt_non_nationals' => (bool) $this->exempt_non_nationals,
            'min_service_days' => $this->min_service_days,
            'legal_reference' => $this->legal_reference,
            'effective_from' => $this->effective_from->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
        ];
    }
}
