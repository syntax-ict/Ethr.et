<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Http\Requests\User\Concerns\ValidatesCustomRoleAssignment;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use ValidatesCustomRoleAssignment;

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
            $this->validateCustomRoleAssignment($v);

            /** @var User|null $target */
            $target = $this->route('user');
            $actor = $this->user();

            // Suspending, deactivating or re-roling a peer at your own level is
            // the organisation admin's: an HR admin could lock a Finance admin
            // out, which `users.delete` (tenant admins only) was meant to keep
            // from them. Seniors are refused by UserController (403).
            $changesRole = $target instanceof User && $this->has('role')
                && $this->input('role') !== $target->role->value;
            $changesStatus = $target instanceof User && $this->has('status')
                && $this->input('status') !== $target->status;

            if (($changesRole || $changesStatus) && $actor !== null
                && ! $actor->isSuperAdmin() && $actor->role->level() < UserRole::TENANT_ADMIN->level()
                && $target->id !== $actor->id && $target->role->level() === $actor->role->level()) {
                $v->errors()->add($changesRole ? 'role' : 'status', __('user.errors.account_at_your_level'));

                return;
            }

            if (! $this->has('role')) {
                return;
            }

            $role = UserRole::tryFrom((string) $this->input('role'));

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

            // Two roles share level 70 with different jobs (audit N95): whoever
            // processes payroll must not also change the accounts it pays into.
            // Below the organisation admin, the other role at your level is
            // not yours to hand out, or an HR admin could open a Finance admin
            // account for themselves.
            if (! $actor->isSuperAdmin() && $actor->role->level() < UserRole::TENANT_ADMIN->level()
                && $role->level() === $actor->role->level() && $role !== $actor->role) {
                $v->errors()->add('role', __('user.errors.role_beside_your_own'));
            }
        });
    }
}
