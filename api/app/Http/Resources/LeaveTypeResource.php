<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'code' => $this->code,
            'default_days' => (float) $this->default_days,
            'accrual_type' => $this->accrual_type?->value,
            'carry_forward' => $this->carry_forward,
            'max_carry_days' => $this->max_carry_days ? (float) $this->max_carry_days : null,
            'requires_approval' => $this->requires_approval,
            'requires_attachment' => $this->requires_attachment,
            'min_notice_days' => $this->min_notice_days,
            'max_consecutive' => $this->max_consecutive,
            'is_paid' => $this->is_paid,
            'is_active' => $this->is_active,
            'gender_restriction' => $this->gender_restriction,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
