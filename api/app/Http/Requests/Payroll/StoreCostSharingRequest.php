<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class StoreCostSharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_public_id' => ['required', 'string', 'exists:employees,public_id'],
            'total_obligation_cents' => ['required', 'integer', 'min:100'],
            // No default. The rate comes from the graduate's own agreement, and
            // silently defaulting to the commonly-cited 10% would withhold a
            // number nobody chose from someone's salary.
            //
            // Capped at 100: a rate above that withholds more than the employee
            // earns, which is a typo every time (e.g. 1000 for 10.00).
            'deduction_rate_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'started_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
