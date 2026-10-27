<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRule;
use App\Models\Tenant;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Overtime pay at the organisation's rates, never below the statutory floor.
 *
 * The floor is the `overtime.minimum_rates` statutory parameter, Labour
 * Proclamation No. 1156/2019 Art. 68(1): 1.5x for overtime between 06:00 and
 * 22:00, 1.75x between 22:00 and 06:00, 2x on a weekly rest day and 2.5x on a
 * public holiday. Until 2026-10-01 a constant here held the repealed 377/2003
 * rates, and every tenant on the defaults was paid below the law; until
 * 2026-10-27 the floor was still a constant. It is a dated row now, scoped by
 * organisation type, so a change in the law is not a deploy.
 */
final class OvertimeCalculator
{
    /**
     * Per-tenant resolved rates, memoized so a payroll run does not re-query
     * once per employee per overtime type.
     *
     * @var array<string, array<string, float>>
     */
    private array $rateCache = [];

    public function __construct(private readonly StatutoryRules $rules) {}

    public function calculate(
        int $basicSalaryCents,
        int $workingDaysPerMonth,
        int $hoursPerDay,
        int $overtimeMinutes,
        string $type = 'normal',
        ?int $tenantId = null,
        ?CarbonInterface $asOf = null,
    ): int {
        if ($overtimeMinutes <= 0 || $workingDaysPerMonth <= 0 || $hoursPerDay <= 0) {
            return 0;
        }

        $rates = $this->ratesFor($tenantId, $asOf);
        $multiplier = $rates[$type] ?? $rates['normal'];

        // basic ÷ (days × hours) an hour, × minutes ÷ 60, × the multiplier:
        // one exact fraction, rounded once.
        return Cents::mulDiv(
            $basicSalaryCents,
            $overtimeMinutes * Cents::scaledRate($multiplier),
            $workingDaysPerMonth * $hoursPerDay * 60 * Cents::RATE_SCALE,
        );
    }

    /**
     * The statutory floor for each rate, for an organisation (by its type) or,
     * with no tenant, for every type. Also what an organisation is paid at
     * for any rate it has not raised.
     *
     * @return array<string, float>
     *
     * @scramble-return array{normal: float, night: float, rest_day: float, holiday: float, holiday_night: float}
     */
    public function minimumRates(?int $tenantId, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);
        $set = $tenant === null ? $this->rules->platform($asOf) : $this->rules->for($tenant, $asOf);

        return array_map(floatval(...), $set->list('overtime.minimum_rates'));
    }

    /**
     * The rates a tenant's overtime is actually paid at, falling back to the
     * statutory floor for any rate the tenant has not raised. Always the same
     * keys as the floor; stated for the API contract, which cannot follow the
     * overrides assigned by key and published a string map.
     *
     * @return array<string, float>
     *
     * @scramble-return array{normal: float, night: float, rest_day: float, holiday: float, holiday_night: float}
     */
    public function ratesFor(?int $tenantId, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $key = ($tenantId ?? 'platform').'@'.$asOf->format('Y-m-d');

        if (isset($this->rateCache[$key])) {
            return $this->rateCache[$key];
        }

        $minimums = $this->minimumRates($tenantId, $asOf);

        if ($tenantId === null) {
            return $this->rateCache[$key] = $minimums;
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
        $rates = $minimums;
        foreach (array_keys($minimums) as $type) {
            if (isset($formula[$type]) && is_numeric($formula[$type])) {
                $rates[$type] = max((float) $formula[$type], $minimums[$type]);
            }
        }

        return $this->rateCache[$key] = $rates;
    }

    /**
     * Drops the memoized rates — call after persisting a rate change so a
     * long-lived worker picks the new values up.
     */
    public function forgetCachedRates(?int $tenantId = null): void
    {
        $prefix = ($tenantId ?? '').'@';
        $this->rateCache = $tenantId === null
            ? []
            : array_filter($this->rateCache, fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }
}
