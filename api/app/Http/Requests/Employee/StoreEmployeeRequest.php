<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Requests\Employee\Concerns\ValidatesRelationTenancy;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    // The seven relation `exists` rules below are unscoped, because Laravel's
    // presence verifier ignores global scopes. The tenant check is a
    // withValidator() hook so that rules() — and therefore the generated API
    // contract — is untouched. See the trait.
    use ValidatesRelationTenancy;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'employee_code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('employees')->where('tenant_id', $tenantId),
            ],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            // Encrypted at rest; a blind index drives duplicate detection during
            // import and device sync (see IDENTITY_RESOLUTION.md).
            'national_id' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'status' => ['nullable', 'string', Rule::enum(EmployeeStatus::class)],
            'hire_date' => ['required', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'tin' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            'position_id' => ['nullable', 'exists:positions,public_id'],
            'grade_id' => ['nullable', 'exists:grades,public_id'],
            'team_id' => ['nullable', 'exists:teams,public_id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,public_id'],
            'supervisor_id' => ['nullable', 'exists:employees,public_id'],

            // Optionally provision a login account for this employee. Requires an
            // email; the person receives an activation link to set their password.
            'create_login' => ['sometimes', 'boolean'],
            'user_role' => ['sometimes', 'string', Rule::enum(UserRole::class)],
        ];
    }
}
