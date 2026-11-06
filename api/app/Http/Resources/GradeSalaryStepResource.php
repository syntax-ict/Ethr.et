<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GradeSalaryStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GradeSalaryStep */
class GradeSalaryStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // A step's salary is pay, as GradeResource's band is.
        $showPay = $request->user()?->hasPermission('employee.viewFinancial') ?? false;

        return [
            'public_id' => $this->public_id,
            'step' => $this->step,
            'salary_cents' => $this->when($showPay, $this->salary_cents),
            'created_at' => $this->created_at,
        ];
    }
}
