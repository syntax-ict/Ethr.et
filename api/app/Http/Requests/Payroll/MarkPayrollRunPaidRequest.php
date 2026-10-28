<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class MarkPayrollRunPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'payment_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
