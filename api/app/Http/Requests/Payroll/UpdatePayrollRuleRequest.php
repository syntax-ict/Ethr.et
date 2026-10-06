<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Payroll\Concerns\PayrollRuleRules;
use App\Models\PayrollRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates a tenant allowance rule. `type` is always required so the `formula`
 * shape can be validated against it, even when only the formula changes.
 */
class UpdatePayrollRuleRequest extends FormRequest
{
    use PayrollRuleRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->payrollRuleRules(['sometimes', 'required']);
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
