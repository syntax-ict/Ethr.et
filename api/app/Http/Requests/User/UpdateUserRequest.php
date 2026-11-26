<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Http\Requests\User\Concerns\ValidatesCustomRoleAssignment;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationOwnership;
use App\Support\CustomRoleAssignment;
use App\Support\Locales;
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
            'role_follows_position' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'suspended'])],
            'custom_role_id' => ['sometimes', 'nullable', 'string', 'exists:custom_roles,public_id'],
            'locale' => ['sometimes', 'string', Locales::rule()],
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
            // Switching "follows the job title" either way is a role change:
            // on may move the role, off pins it. With it on, a role in the
            // same payload is ignored.
            $hasFlag = $target instanceof User && $this->has('role_follows_position');
            $followsOn = $hasFlag && $this->boolean('role_follows_position');
            $changesFlag = $hasFlag && $this->boolean('role_follows_position') !== $target->role_follows_position;
            $changesRole = $target instanceof User && ! $followsOn && $this->has('role')
                && $this->input('role') !== $target->role->value;
            $movesRole = $changesRole || $changesFlag;
            $changesStatus = $target instanceof User && $this->has('status')
                && $this->input('status') !== $target->status;
            // A custom role replaces its holder's permissions, so giving a peer
            // a narrow one locks them out as surely as a status change does.
            $changesCustomRole = $target instanceof User && $this->has('custom_role_id')
                && $this->input('custom_role_id') !== $target->customRole?->public_id;

            // Your own access follows your job title, and only the
            // organisation admin may pin it: otherwise an HR admin could keep
            // a role after losing the title that gave it.
            if ($target instanceof User && $actor !== null && $target->id === $actor->id
                && ! $actor->isSuperAdmin() && $actor->role->level() < UserRole::TENANT_ADMIN->level()
                && ($changesFlag || ($changesRole && $target->role_follows_position))) {
                $v->errors()->add($changesFlag ? 'role_follows_position' : 'role', __('user.errors.own_access_follows_title'));

                return;
            }

            // Your own custom role is another administrator's to change: a
            // custom role replaces every permission you hold, so a second
            // tenant admin could give themselves a narrow one and lose the
            // settings that would let them take it back.
            if ($changesCustomRole && $actor !== null
                && $target->id === $actor->id && ! $actor->isSuperAdmin()) {
                $v->errors()->add('custom_role_id', __('user.errors.own_custom_role'));

                return;
            }

            // Taking a custom role away, or swapping it, changes what its
            // holder may do. When the role they hold now is one this actor
            // could not give, the holder outranks them in everything the level
            // checks below cannot see: those compare the built-in role only.
            // An inactive role grants nothing, so removing it is allowed.
            if ($changesCustomRole && $actor !== null && ! $actor->isSuperAdmin()) {
                $held = $target->customRole;

                if ($held !== null && $held->is_active && ! CustomRoleAssignment::mayAssign($actor, $held)) {
                    $v->errors()->add('custom_role_id', __('user.errors.cannot_modify_higher'));

                    return;
                }
            }

            // The owner stays an active administrator until ownership is
            // handed on: ETHR's support calls them, and only they may give
            // it away (OrganisationOwnership). A custom role replaces their
            // permissions, so it is refused the same way.
            if (($movesRole || $changesCustomRole || ($changesStatus && $this->input('status') !== 'active'))
                && OrganisationOwnership::owns($target, app(CurrentTenant::class)->get())) {
                $v->errors()->add($changesRole ? 'role' : ($movesRole ? 'role_follows_position' : ($changesCustomRole ? 'custom_role_id' : 'status')), __('user.errors.owner_transfer_first'));

                return;
            }

            if (($movesRole || $changesStatus || $changesCustomRole) && $actor !== null
                && ! $actor->isSuperAdmin() && $actor->role->level() < UserRole::TENANT_ADMIN->level()
                && $target->id !== $actor->id && $target->role->level() === $actor->role->level()) {
                $v->errors()->add($changesRole ? 'role' : ($movesRole ? 'role_follows_position' : ($changesStatus ? 'status' : 'custom_role_id')), __('user.errors.account_at_your_level'));

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
