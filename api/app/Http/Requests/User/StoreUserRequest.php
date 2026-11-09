<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Http\Requests\User\Concerns\ValidatesCustomRoleAssignment;
use App\Models\Employee;
use App\Services\CurrentTenant;
use App\Support\RoleAssignment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use ValidatesCustomRoleAssignment;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.invite') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $tenantId)->withoutTrashed(),
            ],
            // Optional login handle for tenants that enable the `username`
            // identifier (see AuthIdentifierResolver). Unique per tenant and
            // matched case-insensitively, so it is stored lower-cased.
            'username' => [
                'nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->where('tenant_id', $tenantId)->withoutTrashed(),
            ],
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
            'employee_id' => ['nullable', 'string', 'exists:employees,public_id'],
            'custom_role_id' => ['nullable', 'string', 'exists:custom_roles,public_id'],
            'send_activation' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateCustomRoleAssignment($v);

            // `employee_id`'s `exists` rule is unscoped: another tenant's
            // employee passed it, and the controller's scoped lookup then
            // silently linked nobody (production QA 2026-10-11, P14). Same
            // generic message as a nonexistent id, so it confirms nothing.
            $employeeId = $this->input('employee_id');
            if (is_string($employeeId) && $employeeId !== ''
                && ! $v->errors()->has('employee_id')
                && ! Employee::where('public_id', $employeeId)->exists()) {
                $v->errors()->add('employee_id', __('validation.exists', ['attribute' => 'employee id']));
            } elseif (is_string($employeeId) && $employeeId !== '' && ! $v->errors()->has('employee_id')
                && self::hasLogin(Employee::where('public_id', $employeeId)->first())) {
                // A login is who an account's punches, payslips and leave belong
                // to. A second one on someone else's record let an HR admin
                // read the CEO's payslips from an inbox they control; linking
                // an existing account already refused this (linkExistingAccount).
                $v->errors()->add('employee_id', __('user.errors.employee_has_login'));
            }

            $role = UserRole::tryFrom((string) $this->input('role'));
            $actor = $this->user();

            if ($role === null || $actor === null) {
                return;
            }

            $refusal = RoleAssignment::refusal($actor, $role);
            if ($refusal !== null) {
                $v->errors()->add('role', __($refusal));
            }
        });
    }

    /** Whether a live account in the organisation is already this employee's login. */
    public static function hasLogin(?Employee $employee): bool
    {
        return $employee !== null && $employee->hasLogin();
    }
}
