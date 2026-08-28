<?php

declare(strict_types=1);

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'date'],
            'branch_public_id' => ['sometimes', 'nullable', 'string', 'exists:branches,public_id'],
            'ethiopian_calendar' => ['sometimes', 'boolean'],
            'recurring' => ['sometimes', 'boolean'],
            'is_estimated' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
