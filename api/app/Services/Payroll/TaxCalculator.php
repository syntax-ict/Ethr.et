<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\TaxBracket;

final class TaxCalculator
{
    /**
     * Per-tenant resolved ladders, memoized so a payroll run resolves the
     * ladder once instead of once per employee.
     *
     * @var array<int, list<array{min: int, max: int|null, rate: float, deduction: int}>>
     */
    private array $bracketCache = [];

    /** @var list<array{min: int, max: int|null, rate: float|int, deduction: int}> */
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

    /**
     * Resolution order: the tenant's own ladder, then the platform-wide
     * (tenant_id = null) ladder, then the hard-coded defaults.
     *
     * A tenant that overrides the ladder must override it completely — mixing
     * its brackets with the platform ones would produce overlapping bands.
     */
    private function getBrackets(?int $tenantId): array
    {
        if ($tenantId === null) {
            return $this->defaultBrackets;
        }

        if (isset($this->bracketCache[$tenantId])) {
            return $this->bracketCache[$tenantId];
        }

        $brackets = $this->effectiveBrackets($tenantId);

        if ($brackets === []) {
            $brackets = $this->effectiveBrackets(null);
        }

        if ($brackets === []) {
            $brackets = $this->defaultBrackets;
        }

        return $this->bracketCache[$tenantId] = $brackets;
    }

    /**
     * Drops the memoized ladder — call after persisting a bracket change so a
     * calculator instance reused within the same request sees the new values.
     */
    public function forgetCachedBrackets(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->bracketCache = [];

            return;
        }

        unset($this->bracketCache[$tenantId]);
    }

    /**
     * @return list<array{min: int, max: int|null, rate: float, deduction: int}>
     */
    private function effectiveBrackets(?int $tenantId): array
    {
        return TaxBracket::query()
            ->withoutGlobalScope('tenant')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->whereDate('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', now());
            })
            ->orderBy('min_amount_cents')
            ->get()
            ->map(fn (TaxBracket $b) => [
                'min' => (int) $b->min_amount_cents,
                // 0 is the open-ended sentinel — the column is NOT NULL.
                'max' => $b->max_amount_cents ? (int) $b->max_amount_cents : null,
                'rate' => (float) $b->rate,
                'deduction' => (int) $b->deduction_cents,
            ])
            ->values()
            ->all();
    }
}
