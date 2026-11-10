<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\WorkforceProfileCatalog;
use App\Support\TenantLanding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'industry' => ['required', 'string', Rule::in(app(IndustryCatalog::class)->keys())],
            'employee_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'staff_band' => ['nullable', 'string', Rule::in(TenantLanding::STAFF_BANDS)],
            'region' => ['nullable', 'string', 'max:100'],
            'workforce_profile' => ['nullable', 'string', Rule::in(app(WorkforceProfileCatalog::class)->keys())],
            'policy_profile' => ['nullable', 'string', Rule::in(app(PolicyProfileCatalog::class)->keys())],
            'organization_type' => ['nullable', 'string', Rule::exists('organization_types', 'code')->where('is_active', true)],
        ];
    }
}
