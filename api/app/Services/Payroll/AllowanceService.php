<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRule;

/**
 * Resolves tenant-configured allowance rules into concrete per-employee
 * amounts. All arithmetic is in integer cents — percentage allowances are
 * rounded (half up) to the nearest cent.
 */
final class AllowanceService
{
    /**
     * Compute the active allowances for an employee against a basic salary.
     *
     * Percentage allowances are computed on the (already prorated) basic so
     * they scale with mid-period proration; fixed allowances are flat amounts.
     * Non-positive amounts are skipped.
     *
     * @return array{
     *   items: list<array{name: string, type: string, amount_cents: int, taxable: bool}>,
     *   total_cents: int,
     *   taxable_cents: int,
     *   non_taxable_cents: int,
     * }
     */
    public function resolve(Employee $employee, int $basicSalaryCents): array
    {
        $rules = PayrollRule::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('category', 'allowance')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = [];
        $total = 0;
        $taxable = 0;
        $nonTaxable = 0;

        foreach ($rules as $rule) {
            $amount = $this->amountFor($rule, $basicSalaryCents);
            if ($amount <= 0) {
                continue;
            }

            $isTaxable = (bool) $rule->is_taxable;

            $items[] = [
                'name' => (string) $rule->name,
                'type' => (string) $rule->type,
                'amount_cents' => $amount,
                'taxable' => $isTaxable,
            ];

            $total += $amount;
            if ($isTaxable) {
                $taxable += $amount;
            } else {
                $nonTaxable += $amount;
            }
        }

        return [
            'items' => $items,
            'total_cents' => $total,
            'taxable_cents' => $taxable,
            'non_taxable_cents' => $nonTaxable,
        ];
    }

    private function amountFor(PayrollRule $rule, int $basicSalaryCents): int
    {
        $formula = is_array($rule->formula) ? $rule->formula : [];

        return match ($rule->type) {
            'percentage' => (int) round($basicSalaryCents * ((float) ($formula['percent'] ?? 0)) / 100),
            default => (int) ($formula['amount_cents'] ?? 0), // 'fixed'
        };
    }
}
