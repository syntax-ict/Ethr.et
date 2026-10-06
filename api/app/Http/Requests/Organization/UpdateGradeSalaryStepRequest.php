<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Grade;
use App\Models\GradeSalaryStep;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGradeSalaryStepRequest extends StoreGradeSalaryStepRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Grade $grade */
        $grade = $this->route('grade');
        /** @var GradeSalaryStep $salaryStep */
        $salaryStep = $this->route('salaryStep');

        return [
            'step' => [
                'required',
                'integer',
                'min:1',
                'max:100',
                Rule::unique('grade_salary_steps', 'step')
                    ->where('grade_id', $grade->id)
                    ->ignore($salaryStep->id),
            ],
            'salary_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Grade $grade */
            $grade = $this->route('grade');
            /** @var GradeSalaryStep $salaryStep */
            $salaryStep = $this->route('salaryStep');
            $salaryCents = $this->integer('salary_cents');

            if ($salaryCents < $grade->min_salary_cents || $salaryCents > $grade->max_salary_cents) {
                $validator->errors()->add('salary_cents', __('personnel.salary_step_out_of_range'));

                return;
            }

            $this->assertMonotonic($validator, $grade, $this->integer('step'), $salaryCents, $salaryStep->id);
        });
    }
}
