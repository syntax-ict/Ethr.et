<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\FeatureOverrideMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantFeatureOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::enum(FeatureOverrideMode::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
