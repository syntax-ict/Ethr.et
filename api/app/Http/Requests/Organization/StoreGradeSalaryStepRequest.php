<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Grade;
use App\Models\GradeSalaryStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGradeSalaryStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('org.create') runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Grade $grade */
        $grade = $this->route('grade');

        return [
            'step' => [
                'required',
                'integer',
                'min:1',
                'max:100',
                Rule::unique('grade_salary_steps', 'step')->where('grade_id', $grade->id),
            ],
            'salary_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Grade $grade */
            $grade = $this->route('grade');
            $salaryCents = $this->integer('salary_cents');

            if ($salaryCents < $grade->min_salary_cents || $salaryCents > $grade->max_salary_cents) {
                $validator->errors()->add('salary_cents', __('personnel.salary_step_out_of_range'));

                return;
            }

            $this->assertMonotonic($validator, $grade, $this->integer('step'), $salaryCents, null);
        });
    }

    /**
     * A salary scale only means something if pay rises (or holds) with step —
     * a step 5 paying less than step 4 is a data-entry error, not a policy
     * this API should silently store.
     */
    protected function assertMonotonic(Validator $validator, Grade $grade, int $step, int $salaryCents, ?int $excludeId): void
    {
        $lower = GradeSalaryStep::where('grade_id', $grade->id)
            ->where('step', '<', $step)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderByDesc('step')
            ->first();

        if ($lower !== null && $salaryCents < $lower->salary_cents) {
            $validator->errors()->add('salary_cents', __('personnel.salary_step_not_monotonic'));
        }

        $higher = GradeSalaryStep::where('grade_id', $grade->id)
            ->where('step', '>', $step)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('step')
            ->first();

        if ($higher !== null && $salaryCents > $higher->salary_cents) {
            $validator->errors()->add('salary_cents', __('personnel.salary_step_not_monotonic'));
        }
    }
}
