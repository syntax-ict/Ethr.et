<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'string', 'exists:employees,public_id'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            // Must be constrained to the enum, as StoreEmployeeRequest already
            // does. A bare `string` here let any value reach the column, and
            // `status` is cast to EmployeeStatus on read — so writing e.g.
            // "banana" made the row throw a ValueError on every subsequent read,
            // 500ing the employee endpoint and any list containing them. The API
            // is also the only way to correct it, so the record was bricked.
            'status' => ['nullable', 'string', Rule::enum(EmployeeStatus::class)],
        ];
    }
}
