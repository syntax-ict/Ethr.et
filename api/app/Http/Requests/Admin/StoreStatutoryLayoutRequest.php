<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Payroll\Concerns\ValidatesPensionScheme;
use App\Services\Payroll\StatutorySchedules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new version of a statutory schedule's layout, from a date. A column's key
 * must be one the schedules can fill (StatutorySchedules::COLUMN_FIELDS); its
 * label may use {employee_rate}, {employer_rate} and {total_rate}.
 */
class StoreStatutoryLayoutRequest extends FormRequest
{
    use ValidatesPensionScheme;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'schedule' => ['required', 'string', Rule::in(StatutorySchedules::SCHEDULES)],
            'scheme' => ['nullable', 'string', 'max:30'],
            'agency' => ['required', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['required', 'date'],
            'columns' => ['required', 'array', 'min:1', 'max:20'],
            'columns.*.key' => ['required', 'string', Rule::in(array_keys(StatutorySchedules::COLUMN_FIELDS))],
            'columns.*.label' => ['required', 'string', 'max:80'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->checkPensionScheme($v, 'scheme');

            $pension = $this->input('schedule') === StatutorySchedules::PENSION;
            if ($pension && ! is_string($this->input('scheme'))) {
                $v->errors()->add('scheme', __('validation.required', ['attribute' => 'scheme']));
            }
            if (! $pension && $this->input('scheme') !== null) {
                $v->errors()->add('scheme', __('validation.prohibited', ['attribute' => 'scheme']));
            }
        });
    }
}
