<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRule;

final class OvertimeCalculator
{
    /**
     * Labour Proclamation No. 1156/2019, Art. 68(1): 1.5x for overtime between
     * 06:00 and 22:00, 1.75x between 22:00 and 06:00, 2x for work on a weekly
     * rest day and 2.5x for work on a public holiday. These are the minimums,
     * and the defaults when a tenant has set nothing higher.
     *
     * Until 2026-10-01 this table held 1.25 / 1.5 / 2.0 / 2.5 — the rates of
     * the repealed Proclamation 377/2003 — with no weekly-rest-day rate at all,
     * and "holiday" (2.0) paid public-holiday daytime work below the 2.5 Art.
     * 68(1)(d) requires. Every tenant on the defaults was paid below the law.
     *
     * @var array<string, float>
     */
    public const DEFAULT_RATES = [
        'normal' => 1.5,
        'night' => 1.75,
        'rest_day' => 2.0,
        'holiday' => 2.5,
        'holiday_night' => 2.5,
    ];

    /**
     * The floor for each rate. Equal to the defaults today; kept separate so a
     * tenant default can move without moving the law.
     *
     * @var array<string, float>
     */
    public const STATUTORY_MINIMUMS = self::DEFAULT_RATES;

    /**
     * Per-tenant resolved rates, memoized so a payroll run does not re-query
     * once per employee per overtime type.
     *
     * @var array<int, array<string, float>>
     */
    private array $rateCache = [];

    public function calculate(
        int $basicSalaryCents,
        int $workingDaysPerMonth,
        int $hoursPerDay,
        int $overtimeMinutes,
        string $type = 'normal',
        ?int $tenantId = null,
    ): int {
        if ($overtimeMinutes <= 0 || $workingDaysPerMonth <= 0 || $hoursPerDay <= 0) {
            return 0;
        }

        $hourlyRate = $basicSalaryCents / ($workingDaysPerMonth * $hoursPerDay);
        $overtimeHours = $overtimeMinutes / 60;

        $rates = $this->ratesFor($tenantId);
        $multiplier = $rates[$type] ?? $rates['normal'];

        return (int) round($hourlyRate * $overtimeHours * $multiplier);
    }

    /**
     * The rates a tenant's overtime is actually paid at, falling back to
     * {@see self::DEFAULT_RATES} for any rate the tenant has not overridden.
     * Always the same five keys; stated for the API contract, which cannot
     * follow the overrides assigned by key and published a string map.
     *
     * @return array<string, float>
     *
     * @scramble-return array{normal: float, night: float, rest_day: float, holiday: float, holiday_night: float}
     */
    public function ratesFor(?int $tenantId): array
    {
        if ($tenantId === null) {
            return self::DEFAULT_RATES;
        }

        if (isset($this->rateCache[$tenantId])) {
            return $this->rateCache[$tenantId];
        }

        $rule = PayrollRule::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('category', 'overtime')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $formula = is_array($rule?->formula) ? $rule->formula : [];

        // A stored rate below the statutory minimum is raised to it: rates
        // saved under the old 1.25 / 1.5 floors are still in the database, and
        // a tenant may pay above the law, never below it.
        $rates = self::DEFAULT_RATES;
        foreach (array_keys(self::DEFAULT_RATES) as $key) {
            if (isset($formula[$key]) && is_numeric($formula[$key])) {
                $rates[$key] = max((float) $formula[$key], self::STATUTORY_MINIMUMS[$key]);
            }
        }

        return $this->rateCache[$tenantId] = $rates;
    }

    /**
     * Drops the memoized rates — call after persisting a rate change so a
     * long-lived worker picks the new values up.
     */
    public function forgetCachedRates(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->rateCache = [];

            return;
        }

        unset($this->rateCache[$tenantId]);
    }
}
