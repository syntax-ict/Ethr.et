<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Payroll\Concerns\PayrollRuleRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a tenant allowance rule. The `formula` shape depends on `type`:
 *   fixed      → {"amount_cents": int}
 *   percentage → {"percent": float}  (of basic salary)
 */
class StorePayrollRuleRequest extends FormRequest
{
    use PayrollRuleRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->payrollRuleRules(['required']);
    }
}
