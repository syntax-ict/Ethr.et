<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adjusts an outstanding loan. The principal (`amount_cents`) and the employee
 * are not editable — correcting either means cancelling the loan and issuing a
 * new one, so the deduction history stays traceable.
 */
class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'monthly_deduction_cents' => ['sometimes', 'required', 'integer', 'min:100'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
