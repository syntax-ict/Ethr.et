<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class ImportCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'import_key' => ['required', 'string', 'max:50'],
            // When true, a login account is provisioned for every imported row
            // that has an email (each receives an activation link).
            'create_logins' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'email', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:20'],
            'rows.*.employee_code' => ['nullable', 'string', 'max:30'],
            'rows.*.gender' => ['nullable', 'string', 'in:male,female'],
            'rows.*.hire_date' => ['required', 'date'],
            'rows.*.department_code' => ['nullable', 'string'],
            'rows.*.branch_code' => ['nullable', 'string'],
            'rows.*.position_code' => ['nullable', 'string'],
            'rows.*.salary_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
