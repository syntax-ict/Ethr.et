<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRule;

final class OvertimeCalculator
{
    /**
     * Ethiopian Labour Proclamation minimums, used when a tenant has not
     * configured its own (higher) rates.
     *
     * @var array<string, float>
     */
    public const DEFAULT_RATES = [
        'normal' => 1.25,
        'night' => 1.5,
        'holiday' => 2.0,
        'holiday_night' => 2.5,
    ];

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
     *
     * @return array<string, float>
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

        $rates = self::DEFAULT_RATES;
        foreach (array_keys(self::DEFAULT_RATES) as $key) {
            if (isset($formula[$key]) && is_numeric($formula[$key])) {
                $rates[$key] = (float) $formula[$key];
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
