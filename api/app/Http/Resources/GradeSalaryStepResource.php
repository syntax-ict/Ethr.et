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
        return [
            'public_id' => $this->public_id,
            'step' => $this->step,
            'salary_cents' => $this->salary_cents,
            'created_at' => $this->created_at,
        ];
    }
}
