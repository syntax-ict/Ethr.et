<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll\Concerns;

/**
 * The allowance-rule fields, shared by `StorePayrollRuleRequest` and
 * `UpdatePayrollRuleRequest`.
 *
 * The cross-field `formula` rules are the part worth keeping in one place: a
 * copy that drifted would let an update store a formula shape the create path
 * refuses. The two requests differ only in how `name` is required, so that is
 * the argument. Scramble evaluates `rules()` at runtime, so returning this
 * array publishes the same contract as spelling it out — `api/openapi.json`
 * and `src/src/api/generated.ts` were regenerated and are byte-identical.
 * Keep explanatory comments off the array keys: Scramble publishes them as
 * OpenAPI descriptions.
 */
trait PayrollRuleRules
{
    /**
     * @param  list<string>  $namePresence
     * @return array<string, list<string>>
     */
    protected function payrollRuleRules(array $namePresence): array
    {
        return [
            'name' => [...$namePresence, 'string', 'max:100'],
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
