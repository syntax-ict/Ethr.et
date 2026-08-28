<?php

declare(strict_types=1);

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'branch_public_id' => ['nullable', 'string', 'exists:branches,public_id'],
            'ethiopian_calendar' => ['nullable', 'boolean'],
            'recurring' => ['nullable', 'boolean'],
            'is_estimated' => ['nullable', 'boolean'],
        ];
    }
}
