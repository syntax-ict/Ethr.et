<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'employee_public_id' => $this->employee?->public_id,
            'basic_salary_cents' => $this->basic_salary_cents,
            'allowances' => $this->allowances,
            'deductions' => $this->deductions,
            'gross_cents' => $this->gross_cents,
            'income_tax_cents' => $this->income_tax_cents,
            'employee_pension_cents' => $this->employee_pension_cents,
            'employer_pension_cents' => $this->employer_pension_cents,
            'other_deductions_cents' => $this->other_deductions_cents,
            'net_cents' => $this->net_cents,
            'calculation_log' => $this->when($request->boolean('include_log'), $this->calculation_log),
        ];
    }
}
