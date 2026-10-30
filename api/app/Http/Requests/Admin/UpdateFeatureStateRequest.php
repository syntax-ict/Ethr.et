<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Feature;
use App\Enums\FeatureState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Checked here rather than in rules(), which Scramble reads into the API
     * contract and which would otherwise need the route key.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $feature = Feature::tryFrom((string) $this->route('key'));
            if ($feature !== null && ! $feature->overridable() && $this->input('state') === FeatureState::Restricted->value) {
                $v->errors()->add('state', __('billing.feature_no_rollout'));
            }
        });
    }
}
