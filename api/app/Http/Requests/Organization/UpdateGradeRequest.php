<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'min_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'max_salary_cents' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The band stays a band: maximum at least the minimum, whichever of the
     * two this request changes. Create checked it (`gte`), update did not, so
     * an edit could save max below min, after which every salary step failed
     * the band check (audit N74). Compared against the stored value for the
     * side not sent.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $grade = $this->route('grade');
            if (! $grade instanceof Grade || $validator->errors()->isNotEmpty()) {
                return;
            }

            $min = (int) ($this->input('min_salary_cents') ?? $grade->min_salary_cents);
            $max = (int) ($this->input('max_salary_cents') ?? $grade->max_salary_cents);

            if ($max < $min) {
                $validator->errors()->add('max_salary_cents', __('validation.gte.numeric', [
                    'attribute' => 'max salary cents',
                    'value' => $min,
                ]));
            }
        });
    }
}
