<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
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
            $role = UserRole::tryFrom((string) $this->input('role'));
            $actor = $this->user();

            if ($role === null || $actor === null) {
                return;
            }

            // super_admin is a platform-level role and is never assignable
            // through tenant user management.
            if ($role === UserRole::SUPER_ADMIN) {
                $v->errors()->add('role', __('user.errors.role_not_assignable'));

                return;
            }

            // No privilege escalation: you cannot grant a role above your own.
            if (! $actor->isSuperAdmin() && $role->level() > $actor->role->level()) {
                $v->errors()->add('role', __('user.errors.role_above_your_level'));
            }
        });
    }
}
