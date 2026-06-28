<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'min_salary_cents' => ['required', 'integer', 'min:0'],
            'max_salary_cents' => ['required', 'integer', 'min:0', 'gte:min_salary_cents'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
