<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use Illuminate\Foundation\Http\FormRequest;

class StageRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_type' => ['sometimes', 'string', 'in:csv,manual'],
            'source_ref' => ['nullable', 'string', 'max:100'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'email', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:50'],
            'rows.*.employee_code' => ['nullable', 'string', 'max:50'],
            'rows.*.hire_date' => ['nullable', 'date'],
        ];
    }
}
