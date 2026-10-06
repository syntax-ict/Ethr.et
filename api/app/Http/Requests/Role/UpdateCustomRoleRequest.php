<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customRole = $this->route('customRole');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('custom_roles')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($customRole->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
