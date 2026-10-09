<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tenant overtime multipliers. The lower bounds are Labour Proclamation
 * 1156/2019 Art. 68(1) — a tenant may pay above them, never below.
 * (They were the repealed 377/2003 rates until 2026-10-01.) `rest_day` is
 * optional so a client that predates it keeps working; the default applies.
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
            'normal' => ['required', 'numeric', 'min:1.5', 'max:10'],
            'night' => ['required', 'numeric', 'min:1.75', 'max:10'],
            'rest_day' => ['sometimes', 'numeric', 'min:2', 'max:10'],
            'holiday' => ['required', 'numeric', 'min:2.5', 'max:10'],
            'holiday_night' => ['required', 'numeric', 'min:2.5', 'max:10'],
        ];
    }
}
