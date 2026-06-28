<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\TaxBracket;

final class TaxCalculator
{
    private array $defaultBrackets = [
        ['min' => 0, 'max' => 60000, 'rate' => 0, 'deduction' => 0],
        ['min' => 60001, 'max' => 165000, 'rate' => 10, 'deduction' => 6000],
        ['min' => 165001, 'max' => 320000, 'rate' => 15, 'deduction' => 14250],
        ['min' => 320001, 'max' => 525000, 'rate' => 20, 'deduction' => 30250],
        ['min' => 525001, 'max' => 780000, 'rate' => 25, 'deduction' => 56500],
        ['min' => 780001, 'max' => 1090000, 'rate' => 30, 'deduction' => 95500],
        ['min' => 1090001, 'max' => null, 'rate' => 35, 'deduction' => 150000],
    ];

    public function calculate(int $grossTaxableCents, ?int $tenantId = null): int
    {
        $brackets = $this->getBrackets($tenantId);

        foreach ($brackets as $bracket) {
            $min = $bracket['min'];
            $max = $bracket['max'];
            $rate = $bracket['rate'];
            $deduction = $bracket['deduction'];

            if ($max === null && $grossTaxableCents >= $min) {
                return (int) round($grossTaxableCents * $rate / 100 - $deduction);
            }

            if ($grossTaxableCents >= $min && $grossTaxableCents <= $max) {
                return (int) round($grossTaxableCents * $rate / 100 - $deduction);
            }
        }

        return 0;
    }

    private function getBrackets(?int $tenantId): array
    {
        if ($tenantId === null) {
            return $this->defaultBrackets;
        }

        $dbBrackets = TaxBracket::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhereNull('tenant_id');
            })
            ->whereDate('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', now());
            })
            ->orderBy('min_amount_cents')
            ->get();

        if ($dbBrackets->isEmpty()) {
            return $this->defaultBrackets;
        }

        return $dbBrackets->map(fn ($b) => [
            'min' => $b->min_amount_cents,
            'max' => $b->max_amount_cents ?: null,
            'rate' => (float) $b->rate,
            'deduction' => $b->deduction_cents,
        ])->toArray();
    }
}
