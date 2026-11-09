<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Http\Requests\User\Concerns\ValidatesCustomRoleAssignment;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationOwnership;
use App\Support\RoleAssignment;
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
            'locale' => ['sometimes', 'string', 'in:en,am,om'],
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
            // A custom role replaces its holder's permissions, so giving a peer
            // a narrow one locks them out as surely as a status change does.
            $changesCustomRole = $target instanceof User && $this->has('custom_role_id')
                && $this->input('custom_role_id') !== $target->customRole?->public_id;

            // The owner stays an active administrator until ownership is
            // handed on: ETHR's support calls them, and only they may give
            // it away (OrganisationOwnership). A custom role replaces their
            // permissions, so it is refused the same way.
            if (($changesRole || $changesCustomRole || ($changesStatus && $this->input('status') !== 'active'))
                && OrganisationOwnership::owns($target, app(CurrentTenant::class)->get())) {
                $v->errors()->add($changesRole ? 'role' : ($changesCustomRole ? 'custom_role_id' : 'status'), __('user.errors.owner_transfer_first'));

                return;
            }

            if (($changesRole || $changesStatus || $changesCustomRole) && $actor !== null
                && ! $actor->isSuperAdmin() && $actor->role->level() < UserRole::TENANT_ADMIN->level()
                && $target->id !== $actor->id && $target->role->level() === $actor->role->level()) {
                $v->errors()->add($changesRole ? 'role' : ($changesStatus ? 'status' : 'custom_role_id'), __('user.errors.account_at_your_level'));

                return;
            }

            // Only a change is a grant: resending someone's current role
            // hands out nothing.
            if (! $changesRole) {
                return;
            }

            $role = UserRole::tryFrom((string) $this->input('role'));

            if ($role === null || $actor === null) {
                return;
            }

            $refusal = RoleAssignment::refusal($actor, $role);
            if ($refusal !== null) {
                $v->errors()->add('role', __($refusal));
            }
        });
    }
}
