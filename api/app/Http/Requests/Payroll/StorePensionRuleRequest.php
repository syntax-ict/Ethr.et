<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Services\Payroll\PensionCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new pension rule for the organisation, from a date. The legal reference
 * is required: a rate with no stated source is the hard-coding this replaced,
 * moved into a form.
 */
class StorePensionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scheme' => ['required', 'string', Rule::in(PensionCalculator::SCHEMES)],
            'employee_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'employer_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'ceiling_cents' => ['nullable', 'integer', 'min:1'],
            'floor_cents' => ['nullable', 'integer', 'min:0'],
            'base' => ['sometimes', 'string', Rule::in(PensionCalculator::BASES)],
            'exempt_non_nationals' => ['sometimes', 'boolean'],
            'min_service_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'legal_reference' => ['required', 'string', 'min:3', 'max:200'],
            'effective_from' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ceiling = $this->input('ceiling_cents');
            $floor = $this->input('floor_cents');
            if (is_numeric($ceiling) && is_numeric($floor) && (int) $floor > (int) $ceiling) {
                $v->errors()->add('floor_cents', __('payroll.pension_floor_above_ceiling'));
            }
        });
    }
}
