<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a tenant allowance rule. The `formula` shape depends on `type`:
 *   fixed      → {"amount_cents": int}
 *   percentage → {"percent": float}  (of basic salary)
 */
class StorePayrollRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'formula' => ['required', 'array'],
            'formula.amount_cents' => ['required_if:type,fixed', 'prohibited_unless:type,fixed', 'integer', 'min:0'],
            'formula.percent' => ['required_if:type,percentage', 'prohibited_unless:type,percentage', 'numeric', 'min:0', 'max:100'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
