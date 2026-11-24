<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization\Concerns;

use App\Enums\UserRole;
use App\Models\Position;
use App\Models\User;
use App\Support\RoleAssignment;
use Illuminate\Contracts\Validation\Validator;

/**
 * Changing the access a job title gives is giving that role to everyone who
 * follows the title, so it takes more than editing the title: the permission
 * to manage users, and the right to hand out both the role it gives now and
 * the one it gave before (RoleAssignment). Clearing the role counts, because
 * it lowers the people who hold it.
 */
trait ValidatesPositionAccess
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('access_role')) {
                return;
            }

            $route = $this->route('position');
            $before = $route instanceof Position ? $route->access_role : null;
            $after = $this->input('access_role');
            $after = is_string($after) && $after !== '' ? $after : null;

            if ($after === $before) {
                return;
            }

            $actor = $this->user();
            if (! $actor instanceof User) {
                return;
            }

            if (! $actor->hasPermission('users.update')) {
                $v->errors()->add('access_role', __('user.errors.title_access_needs_users'));

                return;
            }

            foreach (array_filter([$after, $before]) as $value) {
                $role = UserRole::tryFrom($value);
                $refusal = $role === null ? 'user.errors.role_not_assignable' : RoleAssignment::refusal($actor, $role);

                if ($refusal !== null) {
                    $v->errors()->add('access_role', __($refusal));

                    return;
                }
            }
        });
    }
}
