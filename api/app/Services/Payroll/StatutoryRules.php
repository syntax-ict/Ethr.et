<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\ProfileVersion;
use App\Models\StatutoryParameter;
use App\Models\Tenant;
use App\Services\Tenancy\ProfileContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Resolves the statutory parameters in force for an organisation on a date
 * (docs/payroll/STATUTORY-PARAMETERS.md). This is the one settings resolver:
 * payroll, leave and the settings pages all read through it.
 *
 * The organisation's profile on that date (App\Services\Tenancy\ProfileContext)
 * gives its type, the type's parent, and its sector. For each code, among the
 * platform rows in force, latest first, the most specific wins:
 *   1. a row for the organisation's sector (with its type, its parent, or no type);
 *   2. a row for its type;
 *   3. a row for its type's parent;
 *   4. the row for every type (the baseline).
 * Then, unless that row is `locked`, the organisation's own row in force
 * replaces it.
 *
 * Every value carries where it came from: the layer, the enforcement, the
 * legal reference, the published batch (profile version) and the row.
 *
 * Nothing here has a default. A code with no row in force is absent from the
 * set, and asking for it refuses the run by name (StatutoryRuleSet::get).
 */
final class StatutoryRules
{
    /** @var array<string, StatutoryRuleSet> */
    private array $cache = [];

    /** @var array<int, string>|null profile version id => version */
    private ?array $versions = null;

    public function __construct(private readonly ProfileContext $profiles) {}

    public function for(Tenant $tenant, CarbonInterface $asOf): StatutoryRuleSet
    {
        $key = $tenant->id.'@'.$asOf->format('Y-m-d');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $profile = $this->profiles->for($tenant->id, $asOf);

        return $this->cache[$key] = $this->resolve($tenant->id, $profile['organization_type'], $profile['lineage'], $profile['sector'], $asOf);
    }

    /** The platform's values alone, for a type or for every type: what an organisation that overrides nothing gets. */
    public function platform(CarbonInterface $asOf, ?string $organizationType = null, ?string $sector = null): StatutoryRuleSet
    {
        $key = 'platform:'.($organizationType ?? '*').':'.($sector ?? '*').'@'.$asOf->format('Y-m-d');

        return $this->cache[$key] ??= $this->resolve(null, $organizationType, $this->profiles->lineage($organizationType), $sector, $asOf);
    }

    /** @return list<string> the pension schemes in force today, for validation */
    public function pensionSchemes(): array
    {
        return array_values(array_map(strval(...), $this->platform(Carbon::today())->list('pension.schemes')));
    }

    /** Drops memoized sets, so a long-lived worker sees an edit. */
    public function forget(): void
    {
        $this->cache = [];
        $this->versions = null;
        $this->profiles->forget();
    }

    /**
     * @param  list<string>  $lineage  the type, then its parent
     */
    private function resolve(?int $tenantId, ?string $organizationType, array $lineage, ?string $sector, CarbonInterface $asOf): StatutoryRuleSet
    {
        $on = $asOf->format('Y-m-d');

        $rows = StatutoryParameter::query()
            ->withoutGlobalScope('tenant')
            ->where(fn ($q) => $tenantId === null
                ? $q->whereNull('tenant_id')
                : $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where(fn ($q) => $q->whereNull('organization_type')->orWhereIn('organization_type', $lineage === [] ? [''] : $lineage))
            ->where(fn ($q) => $q->whereNull('sector')->orWhere('sector', $sector ?? ''))
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $rules = [];

        foreach ($rows->groupBy('code') as $code => $candidates) {
            $code = (string) $code;

            $platform = null;
            $best = -1;
            foreach ($candidates as $row) {
                if ($row->tenant_id !== null) {
                    continue;
                }
                $rank = $this->rank($row, $lineage);
                if ($rank > $best) {
                    [$platform, $best] = [$row, $rank];
                }
            }

            if ($platform === null) {
                continue;
            }

            $rules[$code] = $this->describe($platform, $this->layerOf($platform, $lineage));

            if ($tenantId === null || $platform->enforcement === StatutoryParameter::LOCKED) {
                continue;
            }

            $own = $candidates->first(fn (StatutoryParameter $row) => $row->tenant_id !== null && (int) $row->tenant_id === $tenantId);
            if ($own !== null) {
                $rules[$code] = $this->describe($own, 'tenant', $platform);
            }
        }

        ksort($rules);

        return new StatutoryRuleSet($organizationType, $rules, $sector);
    }

    /**
     * How specific a platform row is for this profile; candidates arrive
     * latest first, so among equals the latest is kept.
     *
     * @param  list<string>  $lineage
     */
    private function rank(StatutoryParameter $row, array $lineage): int
    {
        $type = match (true) {
            $row->organization_type === null => 0,
            $row->organization_type === ($lineage[0] ?? null) => 2,
            default => 1,
        };

        return ($row->sector !== null ? 3 : 0) + $type;
    }

    /** @param  list<string>  $lineage */
    private function layerOf(StatutoryParameter $row, array $lineage): string
    {
        return match (true) {
            $row->sector !== null => 'sector',
            $row->organization_type === null => 'baseline',
            $row->organization_type === ($lineage[0] ?? null) => 'organization_type',
            default => 'parent_type',
        };
    }

    /**
     * @return array{value: mixed, source: string, layer: string, enforcement: string, floor_direction: string|null, legal_reference: string|null, confirmed: bool, effective_from: string|null, version: string|null, parameter: string|null}
     */
    private function describe(StatutoryParameter $row, string $layer, ?StatutoryParameter $platform = null): array
    {
        $governing = $platform ?? $row;

        return [
            'value' => $row->value,
            'source' => match ($layer) {
                'tenant' => 'tenant',
                'baseline' => 'platform',
                'sector' => 'platform_sector',
                default => 'platform_type',
            },
            'layer' => $layer,
            'enforcement' => $governing->enforcement,
            'floor_direction' => $governing->floor_direction,
            'legal_reference' => $row->legal_reference,
            'confirmed' => $row->confirmed,
            'effective_from' => $row->effective_from->format('Y-m-d'),
            'version' => $this->versionOf($governing->profile_version_id),
            'parameter' => $row->public_id,
        ];
    }

    private function versionOf(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $this->versions ??= ProfileVersion::query()->pluck('version', 'id')->map(strval(...))->all();

        return $this->versions[$id] ?? null;
    }
}
