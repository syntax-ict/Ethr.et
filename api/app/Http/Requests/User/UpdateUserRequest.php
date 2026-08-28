<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('users.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return [
            // Unique per tenant, ignoring this user's own row so a no-op save passes.
            'username' => [
                'sometimes', 'nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->ignore($target?->id)
                    ->withoutTrashed(),
            ],
            'role' => ['sometimes', 'string', Rule::enum(UserRole::class)],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'suspended'])],
            'custom_role_id' => ['sometimes', 'nullable', 'string', 'exists:custom_roles,public_id'],
            'locale' => ['sometimes', 'string', 'in:en,am'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('role')) {
                return;
            }

            $role = UserRole::tryFrom((string) $this->input('role'));
            $actor = $this->user();

            if ($role === null || $actor === null) {
                return;
            }

            if ($role === UserRole::SUPER_ADMIN) {
                $v->errors()->add('role', __('user.errors.role_not_assignable'));

                return;
            }

            if (! $actor->isSuperAdmin() && $role->level() > $actor->role->level()) {
                $v->errors()->add('role', __('user.errors.role_above_your_level'));
            }
        });
    }
}
