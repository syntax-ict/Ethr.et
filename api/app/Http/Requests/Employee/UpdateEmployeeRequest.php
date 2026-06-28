<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $employee = $this->route('employee');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'employee_code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('employees')->where('tenant_id', $tenantId)->ignore($employee?->id),
            ],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'hire_date' => ['sometimes', 'date'],
            'probation_end_date' => ['nullable', 'date'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'tin' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            'position_id' => ['nullable', 'exists:positions,public_id'],
            'grade_id' => ['nullable', 'exists:grades,public_id'],
            'team_id' => ['nullable', 'exists:teams,public_id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,public_id'],
            'supervisor_id' => ['nullable', 'exists:employees,public_id'],
        ];
    }
}
