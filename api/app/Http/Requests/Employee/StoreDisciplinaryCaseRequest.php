<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\DisciplinaryCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisciplinaryCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', DisciplinaryCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(DisciplinaryCategory::class)],
            'description' => ['required', 'string', 'max:5000'],
            'incident_date' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
