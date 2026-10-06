<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['boolean'],
            'idp_entity_id' => ['nullable', 'string', 'max:500'],
            'idp_sso_url' => ['nullable', 'url', 'max:2048'],
            'idp_slo_url' => ['nullable', 'url', 'max:2048'],
            'idp_certificate' => ['nullable', 'string'],
            'default_role' => ['nullable', 'string', 'in:employee,supervisor,dept_admin,hr_admin'],
            'auto_provision' => ['boolean'],
            'attribute_mapping' => ['nullable', 'array'],
        ];
    }
}
