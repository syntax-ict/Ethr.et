<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Enums\OrgScope;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('custom_roles')->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'org_scope' => ['sometimes', 'string', Rule::enum(OrgScope::class)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    /**
     * A platform ability is refused like an unknown one. `exists` let
     * `admin.manage` through, and a role holding it opened the platform API
     * (audit N86). A hook rather than a rule, so `rules()` and the published
     * contract stay as they are.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('permissions', []) as $i => $name) {
                if (in_array($name, Permission::PLATFORM_ONLY, true)) {
                    $validator->errors()->add("permissions.{$i}", __('validation.exists', [
                        'attribute' => "permissions.{$i}",
                    ]));
                }
            }
        });
    }
}
