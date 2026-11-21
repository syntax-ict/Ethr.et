<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class VoidPayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'acknowledged_settled_pay_differences' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
