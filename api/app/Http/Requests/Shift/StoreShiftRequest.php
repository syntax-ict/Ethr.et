<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'grace_minutes' => ['integer', 'min:0', 'max:120'],
            'early_departure_minutes' => ['integer', 'min:0', 'max:120'],
            'break_minutes' => ['integer', 'min:0', 'max:180'],
            'working_days' => ['string', 'regex:/^[0-7](,[0-7])*$/'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
