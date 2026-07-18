<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            // Ethiopian month 1-13 (Meskerem = 1 ... Pagume = 13). Fiscal years
            // in practice start at Meskerem 1 (private) or Hamle 1 = month 7
            // (government), but any Ethiopian month is accepted.
            'settings.fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,13'],
            'settings.pagumen_proration_strategy' => ['sometimes', 'string', 'in:full_month,daily_rate'],
        ];
    }
}
