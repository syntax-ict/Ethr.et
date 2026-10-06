<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\IndustryCatalog;
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
            'region' => ['nullable', 'string', 'max:100'],
        ];
    }
}
