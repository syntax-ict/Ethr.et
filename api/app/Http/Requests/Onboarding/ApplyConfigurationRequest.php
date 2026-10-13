<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\WorkforceProfileCatalog;
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
            'workforce_profile' => ['nullable', 'string', Rule::in(app(WorkforceProfileCatalog::class)->keys())],
            'policy_profile' => ['nullable', 'string', Rule::in(app(PolicyProfileCatalog::class)->keys())],
            'plan' => ['required', 'array'],
            'plan.branches' => ['sometimes', 'array', 'max:100'],
            'plan.departments' => ['sometimes', 'array', 'max:200'],
            'plan.positions' => ['sometimes', 'array', 'max:200'],
            'plan.grades' => ['sometimes', 'array', 'max:100'],
            'plan.shifts' => ['sometimes', 'array', 'max:50'],
            'plan.leave_types' => ['sometimes', 'array', 'max:100'],
            'plan.rotations' => ['sometimes', 'array', 'max:20'],
            'plan.rotations.*.steps' => ['sometimes', 'array', 'max:366'],
            'plan.allowances' => ['sometimes', 'array', 'max:50'],
            'plan.attendance' => ['sometimes', 'array'],
            'plan.holidays' => ['sometimes'],
            'plan.settings' => ['sometimes', 'array'],
            'save' => ['sometimes', 'boolean'],
        ];
    }
}
