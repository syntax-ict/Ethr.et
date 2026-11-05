<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Grade */
class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Grade names are for everyone; the salary band is pay, shown to
        // holders of employee.viewFinancial (owner decision 2026-11-04, A-Z
        // review C-10). Every employee could read every band.
        $showPay = $request->user()?->hasPermission('employee.viewFinancial') ?? false;

        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'min_salary_cents' => $this->when($showPay, $this->min_salary_cents),
            'max_salary_cents' => $this->when($showPay, $this->max_salary_cents),
            'sort_order' => $this->sort_order,
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
