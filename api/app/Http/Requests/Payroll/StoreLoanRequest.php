<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_public_id' => ['required', 'string', 'exists:employees,public_id'],
            'amount_cents' => ['required', 'integer', 'min:100'],
            'monthly_deduction_cents' => ['required', 'integer', 'min:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
