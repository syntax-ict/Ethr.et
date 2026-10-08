<?php

declare(strict_types=1);

namespace App\Http\Requests\User\Concerns;

use App\Models\CustomRole;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * A custom role may be assigned only if it is this tenant's, and only by
 * someone who already holds everything it grants (audit N87).
 *
 * Before: `exists:custom_roles,public_id` passed another tenant's role, which
 * the scoped lookup then resolved to null, so an update silently cleared the
 * user's role; and anyone with `users.update` could hand out any role, so an
 * HR admin could give themselves a role carrying `settings.manage` or
 * `payroll.approve`. The base `role` field already refused a role above the
 * actor's level; this is the same rule for the permission set a custom role
 * replaces it with.
 *
 * @mixin FormRequest
 */
trait ValidatesCustomRoleAssignment
{
    protected function validateCustomRoleAssignment(Validator $validator): void
    {
        $publicId = $this->input('custom_role_id');
        $actor = $this->user();

        if (! is_string($publicId) || $publicId === '' || $actor === null || $validator->errors()->has('custom_role_id')) {
            return;
        }

        // Through the tenant scope, with the message a missing id gets, so a
        // public id from another tenant is neither accepted nor confirmed.
        $role = CustomRole::where('public_id', $publicId)->first();
        if ($role === null) {
            $validator->errors()->add('custom_role_id', __('validation.exists', [
                'attribute' => 'custom role id',
            ]));

            return;
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        $granted = Permission::permissionsForCustomRole($role->id);
        if (array_diff($granted, $actor->permissionNames()) !== []) {
            $validator->errors()->add('custom_role_id', __('user.errors.role_above_your_level'));
        }
    }
}
