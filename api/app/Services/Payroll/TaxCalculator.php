<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Exceptions\PayrollRunRefused;
use App\Models\TaxBracket;
use App\Models\Tenant;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Employment income tax from the ladder in force (`tax_brackets`), never from
 * constants. Since 2026-10-27 there is no ladder in code at all: the 979/2016
 * and 1395/2025 ladders are platform rows (TaxBracketSeeder, and the
 * 2026_08_29 migration that applied the amendment), and a date with no ladder
 * in force refuses the run by name rather than taxing at a guess.
 *
 * Resolution: the organisation's own ladder, then the platform ladder for its
 * organisation type, then the platform ladder for every type. A ladder is
 * taken whole, never mixed with another, or bands would overlap.
 */
final class TaxCalculator
{
    /**
     * Resolved ladders, memoized so a payroll run resolves once rather than
     * once per employee. Keyed by tenant and by the as-of date, because a
     * June-2025 run must not reuse a July-2025 resolution.
     *
     * @var array<string, array{brackets: list<array{min: int, max: int|null, rate: float, deduction: int}>, legal_reference: string|null, effective_from: string|null, source: string}>
     */
    private array $cache = [];

    /** @var array<int, string> tenant id => organisation type */
    private array $types = [];

    /**
     * @param  CarbonInterface|null  $asOf  the date the ladder is resolved for —
     *                                      pass the payroll period start, not the
     *                                      run date, so reprocessing an old period
     *                                      reproduces the tax withheld at the time.
     */
    public function calculate(int $grossTaxableCents, ?int $tenantId = null, ?CarbonInterface $asOf = null): int
    {
        return self::apply($grossTaxableCents, $this->ladder($tenantId, $asOf ?? Carbon::now()));
    }

    /**
     * Tax on an amount under a given ladder: `amount × rate − deduction` for
     * the band the amount falls in, the quick form of the statutory
     * cumulative one.
     *
     * @param  list<array{min: int, max: int|null, rate: float|int|string, deduction: int}>  $brackets
     */
    public static function apply(int $grossTaxableCents, array $brackets): int
    {
        foreach ($brackets as $bracket) {
            $max = $bracket['max'];

            if ($grossTaxableCents >= $bracket['min'] && ($max === null || $grossTaxableCents <= $max)) {
                return Cents::percentOf($grossTaxableCents, $bracket['rate']) - $bracket['deduction'];
            }
        }

        return 0;
    }

    /**
     * The ladder `calculate()` applies for this tenant and date, band by band.
     * Written into each entry's calculation_log since version 1.4, so a past
     * month can be re-taxed from its own log even if the rows it was read
     * from are gone.
     *
     * @return list<array{min: int, max: int|null, rate: float, deduction: int}>
     */
    public function ladder(?int $tenantId, CarbonInterface $asOf): array
    {
        return $this->resolve($tenantId, $asOf)['brackets'];
    }

    /**
     * Which ladder `calculate()` uses for this tenant and date, and the law it
     * implements — written into each entry's calculation_log and printed on
     * the statutory schedules. `source` is tenant, platform_type or platform.
     *
     * @return array{legal_reference: string|null, effective_from: string|null, source: string}
     */
    public function basis(?int $tenantId, CarbonInterface $asOf): array
    {
        $resolved = $this->resolve($tenantId, $asOf);

        return [
            'legal_reference' => $resolved['legal_reference'],
            'effective_from' => $resolved['effective_from'],
            'source' => $resolved['source'],
        ];
    }

    /**
     * @return array{brackets: list<array{min: int, max: int|null, rate: float, deduction: int}>, legal_reference: string|null, effective_from: string|null, source: string}
     */
    private function resolve(?int $tenantId, CarbonInterface $asOf): array
    {
        $key = ($tenantId ?? 'platform').'@'.$asOf->format('Y-m-d');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $type = $tenantId === null ? null : $this->organizationType($tenantId);

        $candidates = [];
        if ($tenantId !== null) {
            $candidates[] = ['tenant', $tenantId, null];
        }
        if ($type !== null) {
            $candidates[] = ['platform_type', null, $type];
        }
        $candidates[] = ['platform', null, null];

        foreach ($candidates as [$source, $owner, $organizationType]) {
            $rows = $this->rowsInForce($owner, $organizationType, $asOf);

            if ($rows->isNotEmpty()) {
                $first = $rows->first();

                return $this->cache[$key] = [
                    'brackets' => $rows->map(fn (TaxBracket $b) => [
                        'min' => (int) $b->min_amount_cents,
                        // 0 is the open-ended sentinel — the column is NOT NULL.
                        'max' => $b->max_amount_cents ? (int) $b->max_amount_cents : null,
                        'rate' => (float) $b->rate,
                        'deduction' => (int) $b->deduction_cents,
                    ])->values()->all(),
                    'legal_reference' => $first->getAttribute('legal_reference'),
                    'effective_from' => $first->effective_from->format('Y-m-d'),
                    'source' => $source,
                ];
            }
        }

        throw PayrollRunRefused::noRuleInForce('income_tax.ladder');
    }

    /**
     * One owner's ladder in force on the date. A platform ladder for a type
     * is only that type's rows; the ladder for every type is the rows with no
     * type, so the two never mix.
     *
     * @return Collection<int, TaxBracket>
     */
    private function rowsInForce(?int $tenantId, ?string $organizationType, CarbonInterface $asOf): Collection
    {
        $on = $asOf->format('Y-m-d');

        return TaxBracket::query()
            ->withoutGlobalScope('tenant')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id')->when(
                    $organizationType === null,
                    fn ($q) => $q->whereNull('organization_type'),
                    fn ($q) => $q->where('organization_type', $organizationType),
                ),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderBy('min_amount_cents')
            ->get();
    }

    private function organizationType(int $tenantId): ?string
    {
        if (! array_key_exists($tenantId, $this->types)) {
            $type = Tenant::query()->whereKey($tenantId)->value('organization_type');
            $this->types[$tenantId] = is_string($type) ? $type : '';
        }

        return $this->types[$tenantId] !== '' ? $this->types[$tenantId] : null;
    }
}
