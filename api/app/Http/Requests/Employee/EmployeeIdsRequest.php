<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'filter' => ['nullable', 'array'],
            'filter.status' => ['nullable', 'string', 'max:32'],
            'filter.archived' => ['nullable', 'boolean'],
            'filter.nationality_needs_review' => ['nullable', 'boolean'],
            'filter.missing' => ['nullable', 'string', 'max:32'],
            'filter.department_id' => ['nullable', 'string', 'max:26'],
            'filter.branch_id' => ['nullable', 'string', 'max:26'],
            'filter.position_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
