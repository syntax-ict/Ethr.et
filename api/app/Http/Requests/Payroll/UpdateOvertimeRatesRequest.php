<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tenant overtime multipliers. The lower bounds are the Ethiopian Labour
 * Proclamation minimums — a tenant may pay above them, never below.
 */
class UpdateOvertimeRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'normal' => ['required', 'numeric', 'min:1.25', 'max:10'],
            'night' => ['required', 'numeric', 'min:1.5', 'max:10'],
            'holiday' => ['required', 'numeric', 'min:2', 'max:10'],
            'holiday_night' => ['required', 'numeric', 'min:2.5', 'max:10'],
        ];
    }
}
