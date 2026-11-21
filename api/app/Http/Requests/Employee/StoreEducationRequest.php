<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EducationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEducationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'institution' => ['required', 'string', 'max:255'],
            'degree' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', Rule::enum(EducationLevel::class)],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'grade' => ['nullable', 'string', 'max:50'],
        ];
    }
}
