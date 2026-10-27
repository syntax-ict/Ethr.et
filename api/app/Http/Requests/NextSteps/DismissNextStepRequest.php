<?php

declare(strict_types=1);

namespace App\Http\Requests\NextSteps;

use App\Enums\NextStepId;
use App\Enums\NextStepSeverity;
use App\Services\NextSteps\NextStepsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DismissNextStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', 'max:64'],
            'snooze_days' => ['nullable', 'integer', 'min:1', 'max:'.NextStepsService::SNOOZE_MAX_DAYS],
        ];
    }

    /**
     * A blocker keeps someone from working, so it can only be put away for a
     * few days. The rule needs the step the route named, which `rules()` is not
     * the place for: Scramble reads that array to build the API contract.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $step = $this->route('step');

            if (! $step instanceof NextStepId || $step->severity() !== NextStepSeverity::Blocker) {
                return;
            }

            $days = $this->input('snooze_days');

            if ($days === null || $days === '') {
                $validator->errors()->add('snooze_days', __('validation.required', ['attribute' => 'snooze days']));
            } elseif ((int) $days > NextStepsService::BLOCKER_SNOOZE_MAX_DAYS) {
                $validator->errors()->add('snooze_days', __('validation.max.numeric', [
                    'attribute' => 'snooze days',
                    'max' => NextStepsService::BLOCKER_SNOOZE_MAX_DAYS,
                ]));
            }
        });
    }
}
