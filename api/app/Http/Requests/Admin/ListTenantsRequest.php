<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The platform console's organisation list: only the filters that name a kind
 * in law are validated here. The older filters (status, stage, health) keep
 * their lenient handling in the controller.
 */
class ListTenantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter.organization_type' => ['nullable', 'string', Rule::in(OrganizationType::activeCodes())],
            'filter.organization_type_group' => ['nullable', 'string', Rule::in(OrganizationType::GROUPS)],
        ];
    }
}
