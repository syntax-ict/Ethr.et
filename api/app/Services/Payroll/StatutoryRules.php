<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryParameter;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Resolves the statutory parameters in force for an organisation on a date
 * (docs/payroll/STATUTORY-PARAMETERS.md).
 *
 * For each code, in order:
 *   1. the platform row for the organisation's type, else the platform row
 *      for every type, whichever is in force, latest first;
 *   2. when that row allows it, the organisation's own row, else its
 *      equivalent setting from before this table existed.
 *
 * Nothing here has a default. A code with no row in force is absent from the
 * set, and asking for it refuses the run by name (StatutoryRuleSet::get).
 */
final class StatutoryRules
{
    /**
     * Settings an organisation could already change before parameters were
     * rows. They keep working as its override, so no organisation's payroll
     * moved when the table arrived.
     */
    private const SETTING_OVERRIDES = [
        'pension.default_scheme' => 'pension_scheme',
        'fiscal_year.start_month' => 'fiscal_year_start_month',
        'pagume.proration' => 'pagumen_proration_strategy',
    ];

    /** @var array<string, StatutoryRuleSet> */
    private array $cache = [];

    public function for(Tenant $tenant, CarbonInterface $asOf): StatutoryRuleSet
    {
        $key = $tenant->id.'@'.$asOf->format('Y-m-d');

        return $this->cache[$key] ??= $this->resolve($tenant, $tenant->organization_type, $asOf);
    }

    /** The platform's values alone, for a type or for every type: what an organisation that overrides nothing gets. */
    public function platform(CarbonInterface $asOf, ?string $organizationType = null): StatutoryRuleSet
    {
        $key = 'platform:'.($organizationType ?? '*').'@'.$asOf->format('Y-m-d');

        return $this->cache[$key] ??= $this->resolve(null, $organizationType, $asOf);
    }

    /** @return list<string> the pension schemes in force today, for validation */
    public function pensionSchemes(): array
    {
        return array_values(array_map(strval(...), $this->platform(Carbon::today())->list('pension.schemes')));
    }

    /** Drops memoized sets, so a long-lived worker sees a console edit. */
    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(?Tenant $tenant, ?string $organizationType, CarbonInterface $asOf): StatutoryRuleSet
    {
        $on = $asOf->format('Y-m-d');

        $rows = StatutoryParameter::query()
            ->withoutGlobalScope('tenant')
            ->where(fn ($q) => $tenant === null
                ? $q->whereNull('tenant_id')
                : $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
            ->where(fn ($q) => $q->whereNull('organization_type')->orWhere('organization_type', $organizationType))
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $rules = [];

        foreach ($rows->groupBy('code') as $code => $candidates) {
            $code = (string) $code;
            $platform = $candidates->first(fn (StatutoryParameter $row) => $row->tenant_id === null && $row->organization_type !== null)
                ?? $candidates->first(fn (StatutoryParameter $row) => $row->tenant_id === null && $row->organization_type === null);

            if ($platform === null) {
                continue;
            }

            $rules[$code] = $this->describe($platform, $platform->organization_type !== null ? 'platform_type' : 'platform');

            if ($tenant === null || ! $platform->tenant_overridable) {
                continue;
            }

            $own = $candidates->first(fn (StatutoryParameter $row) => $row->tenant_id !== null && (int) $row->tenant_id === $tenant->id);
            if ($own !== null) {
                $rules[$code] = $this->describe($own, 'tenant');

                continue;
            }

            $setting = self::SETTING_OVERRIDES[$code] ?? null;
            $settings = $tenant->getAttribute('settings');
            $value = $setting !== null && is_array($settings) ? ($settings[$setting] ?? null) : null;
            if (is_scalar($value) && $value !== '') {
                $rules[$code] = [
                    'value' => is_int($platform->value) && is_numeric($value) ? (int) $value : $value,
                    'source' => 'tenant_setting',
                    'legal_reference' => null,
                    'confirmed' => false,
                    'effective_from' => null,
                    'parameter' => null,
                ];
            }
        }

        ksort($rules);

        return new StatutoryRuleSet($organizationType, $rules);
    }

    /** @return array{value: mixed, source: string, legal_reference: string|null, confirmed: bool, effective_from: string|null, parameter: string|null} */
    private function describe(StatutoryParameter $row, string $source): array
    {
        return [
            'value' => $row->value,
            'source' => $source,
            'legal_reference' => $row->legal_reference,
            'confirmed' => $row->confirmed,
            'effective_from' => $row->effective_from->format('Y-m-d'),
            'parameter' => $row->public_id,
        ];
    }
}
