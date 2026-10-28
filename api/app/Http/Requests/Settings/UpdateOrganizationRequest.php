<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'organization_type' => ['sometimes', 'string', 'max:40'],
            'timezone' => ['sometimes', 'string', 'max:50', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'in:en,am,om,ti,so'],
        ];
    }

    /**
     * The organisation type must be an active row of the catalogue. Checked
     * here rather than with `Rule::in()` because the catalogue is a table,
     * and Scramble evaluates rules() where no database may be reachable.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->input('organization_type');

            if (is_string($type) && ! in_array($type, OrganizationType::activeCodes(), true)) {
                $v->errors()->add('organization_type', __('validation.in', ['attribute' => 'organization type']));
            }
        });
    }
}
