<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\FeatureState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeatureStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::enum(FeatureState::class)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
