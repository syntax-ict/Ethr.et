<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_public_id' => ['required', 'string', 'size:26'],
            'assignable_type' => ['required', 'string', 'in:employee,department,branch'],
            'assignable_public_id' => ['required', 'string', 'size:26'],
            'effective_from' => ['required', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
