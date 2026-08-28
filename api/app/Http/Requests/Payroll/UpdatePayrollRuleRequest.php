<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Models\PayrollRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates a tenant allowance rule. `type` is always required so the `formula`
 * shape can be validated against it, even when only the formula changes.
 */
class UpdatePayrollRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'formula' => ['required', 'array'],
            'formula.amount_cents' => ['required_if:type,fixed', 'prohibited_unless:type,fixed', 'integer', 'min:0'],
            'formula.percent' => ['required_if:type,percentage', 'prohibited_unless:type,percentage', 'numeric', 'min:0', 'max:100'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * `type` drives formula validation, so fall back to the stored value when
     * the client sends only a partial update.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            $rule = $this->route('payrollRule');

            if ($rule instanceof PayrollRule) {
                $this->merge(['type' => $rule->type]);
            }
        }
    }
}
