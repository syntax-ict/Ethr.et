<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\IndustryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts a (possibly edited) configuration plan for provisioning. Item shapes
 * are validated only loosely here — OrganizationProvisioner normalizes every
 * entry defensively — but array sizes are capped so an admin cannot push an
 * abusive payload through the create path.
 */
class ApplyConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'industry' => ['nullable', 'string', Rule::in(app(IndustryCatalog::class)->keys())],
            'plan' => ['required', 'array'],
            'plan.branches' => ['sometimes', 'array', 'max:100'],
            'plan.departments' => ['sometimes', 'array', 'max:200'],
            'plan.positions' => ['sometimes', 'array', 'max:200'],
            'plan.grades' => ['sometimes', 'array', 'max:100'],
            'plan.shifts' => ['sometimes', 'array', 'max:50'],
            'plan.leave_types' => ['sometimes', 'array', 'max:100'],
            'plan.holidays' => ['sometimes'],
            'plan.settings' => ['sometimes', 'array'],
            'save' => ['sometimes', 'boolean'],
        ];
    }
}
