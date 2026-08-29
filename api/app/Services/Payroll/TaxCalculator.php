<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\TaxBracket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class TaxCalculator
{
    /**
     * Resolved ladders, memoized so a payroll run resolves once rather than
     * once per employee. Keyed by tenant **and** by the as-of date, because
     * two ladders now exist and a June-2025 run must not reuse a July-2025
     * resolution.
     *
     * @var array<string, list<array{min: int, max: int|null, rate: float, deduction: int}>>
     */
    private array $bracketCache = [];

    /**
     * Employment income tax, Proclamation No. 1395/2025 — in force since
     * 7 July 2025, amending Proclamation No. 979/2016.
     *
     * Six bands, not seven: the tax-free threshold tripled from ETB 600 to
     * ETB 2,000 and the lowest rate rose from 10% to 15%. Monthly figures, in
     * integer cents.
     *
     * `deduction` is the constant of the quick-calculation form
     * `tax = gross × rate − deduction`, derived from the statutory cumulative
     * form. Cross-checked both ways: band 3 is "ETB 300 + 20% of the amount
     * over 4,000", i.e. 0.20g − 800 + 300 = 0.20g − 500, hence 50000 cents.
     *
     * @see self::SUPERSEDED_BRACKETS for the 979/2016 ladder, still applied to
     *      periods before the amendment.
     *
     * @var list<array{min: int, max: int|null, rate: float|int, deduction: int}>
     */
    public const CURRENT_BRACKETS = [
        ['min' => 0, 'max' => 200000, 'rate' => 0, 'deduction' => 0],
        ['min' => 200001, 'max' => 400000, 'rate' => 15, 'deduction' => 30000],
        ['min' => 400001, 'max' => 700000, 'rate' => 20, 'deduction' => 50000],
        ['min' => 700001, 'max' => 1000000, 'rate' => 25, 'deduction' => 85000],
        ['min' => 1000001, 'max' => 1400000, 'rate' => 30, 'deduction' => 135000],
        // null, not 0, marks the open-ended band in this in-memory form. The
        // `tax_brackets` table stores 0 for it because the column is NOT NULL;
        // TaxBracketSeeder converts on the way in and effectiveBrackets() on
        // the way out.
        ['min' => 1400001, 'max' => null, 'rate' => 35, 'deduction' => 205000],
    ];

    /**
     * The Proclamation No. 979/2016 ladder, in force 8 July 2016 – 6 July 2025.
     *
     * Kept rather than deleted because payroll is re-runnable: voiding and
     * reprocessing a period from before the amendment must reproduce the tax
     * that was actually withheld at the time, not today's.
     *
     * @var list<array{min: int, max: int|null, rate: float|int, deduction: int}>
     */
    public const SUPERSEDED_BRACKETS = [
        ['min' => 0, 'max' => 60000, 'rate' => 0, 'deduction' => 0],
        ['min' => 60001, 'max' => 165000, 'rate' => 10, 'deduction' => 6000],
        ['min' => 165001, 'max' => 320000, 'rate' => 15, 'deduction' => 14250],
        ['min' => 320001, 'max' => 525000, 'rate' => 20, 'deduction' => 30250],
        ['min' => 525001, 'max' => 780000, 'rate' => 25, 'deduction' => 56500],
        ['min' => 780001, 'max' => 1090000, 'rate' => 30, 'deduction' => 95500],
        ['min' => 1090001, 'max' => null, 'rate' => 35, 'deduction' => 150000],
    ];

    /** The day Proclamation 1395/2025 took effect. */
    public const AMENDMENT_1395_EFFECTIVE_FROM = '2025-07-07';

    /**
     * @param  CarbonInterface|null  $asOf  the date the ladder is resolved for —
     *                                      pass the payroll period start, not the
     *                                      run date, so reprocessing an old period
     *                                      reproduces the tax withheld at the time.
     */
    public function calculate(int $grossTaxableCents, ?int $tenantId = null, ?CarbonInterface $asOf = null): int
    {
        $brackets = $this->getBrackets($tenantId, $asOf ?? Carbon::now());

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
    private function getBrackets(?int $tenantId, CarbonInterface $asOf): array
    {
        if ($tenantId === null) {
            return $this->fallbackBrackets($asOf);
        }

        $key = $tenantId.'@'.$asOf->format('Y-m-d');

        if (isset($this->bracketCache[$key])) {
            return $this->bracketCache[$key];
        }

        $brackets = $this->effectiveBrackets($tenantId, $asOf);

        if ($brackets === []) {
            $brackets = $this->effectiveBrackets(null, $asOf);
        }

        if ($brackets === []) {
            $brackets = $this->fallbackBrackets($asOf);
        }

        return $this->bracketCache[$key] = $brackets;
    }

    /**
     * The hard-coded ladder for a date, used only when the database defines
     * none. Date-aware for the same reason the database lookup is: a
     * deployment that never seeded `tax_brackets` still has to tax a
     * pre-amendment period at the pre-amendment rates.
     *
     * @return list<array{min: int, max: int|null, rate: float|int, deduction: int}>
     */
    private function fallbackBrackets(CarbonInterface $asOf): array
    {
        return $asOf->lt(Carbon::parse(self::AMENDMENT_1395_EFFECTIVE_FROM))
            ? self::SUPERSEDED_BRACKETS
            : self::CURRENT_BRACKETS;
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

        // Every as-of date for this tenant, not one key. The cache is keyed
        // "{tenantId}@{Y-m-d}" since the ladder became date-dependent, so
        // unsetting by the bare tenant id — which is what this did — silently
        // matched nothing and left a stale ladder in place after a bracket edit.
        $prefix = $tenantId.'@';

        foreach (array_keys($this->bracketCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->bracketCache[$key]);
            }
        }
    }

    /**
     * @return list<array{min: int, max: int|null, rate: float, deduction: int}>
     */
    private function effectiveBrackets(?int $tenantId, CarbonInterface $asOf): array
    {
        $on = $asOf->format('Y-m-d');

        return TaxBracket::query()
            ->withoutGlobalScope('tenant')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->whereDate('effective_from', '<=', $on)
            ->where(function ($q) use ($on) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $on);
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
