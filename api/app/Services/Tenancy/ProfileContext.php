<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\OrganizationType;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use Carbon\CarbonInterface;

/**
 * The profile an organisation's statutory rules resolve against on a date:
 * its organisation type, the types those rules are read through (the type,
 * then its parent), and its sector.
 *
 * Read from the assignment in force on the date (tenant_profile_assignments).
 * A date before the first assignment takes the first one, which is the type
 * the organisation registered with; an organisation with no assignment at all
 * (created before its row was written) takes `tenants.organization_type` and
 * the industry its configuration recorded.
 *
 * Every lookup states `tenant_id`, so it holds without a resolved tenant, in a
 * queued job or the platform console. Memoized for the request or job: the
 * container binds it scoped, and OrganisationProfile forgets it on a write.
 */
final class ProfileContext
{
    /** @var array<int, list<array{organization_type: string, sector: string|null, effective_from: string}>> */
    private array $assignments = [];

    /** @var array<string, list<string>> */
    private array $lineages = [];

    /**
     * @return array{organization_type: string|null, lineage: list<string>, sector: string|null}
     */
    public function for(int $tenantId, CarbonInterface $asOf): array
    {
        $on = $asOf->format('Y-m-d');
        $rows = $this->assignmentsOf($tenantId);

        $current = null;
        foreach ($rows as $row) {
            if ($row['effective_from'] <= $on) {
                $current = $row;
            }
        }
        $current ??= $rows[0] ?? null;

        if ($current === null) {
            $tenant = Tenant::query()->whereKey($tenantId)->first(['id', 'organization_type', 'settings']);
            $type = $tenant !== null && $tenant->organization_type !== '' ? $tenant->organization_type : null;
            $settings = $tenant?->getAttribute('settings');
            $industry = is_array($settings) && is_string($settings['industry'] ?? null) ? $settings['industry'] : null;
            $current = ['organization_type' => $type, 'sector' => $industry];
        }

        return [
            'organization_type' => $current['organization_type'],
            'lineage' => $this->lineage($current['organization_type']),
            'sector' => $current['sector'],
        ];
    }

    /** @return list<string> the type, then its parent; empty for no type */
    public function lineage(?string $organizationType): array
    {
        if ($organizationType === null || $organizationType === '') {
            return [];
        }

        return $this->lineages[$organizationType] ??= OrganizationType::lineage($organizationType);
    }

    public function forget(): void
    {
        $this->assignments = [];
        $this->lineages = [];
    }

    /** @return list<array{organization_type: string, sector: string|null, effective_from: string}> oldest first */
    private function assignmentsOf(int $tenantId): array
    {
        return $this->assignments[$tenantId] ??= TenantProfileAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderBy('effective_from')
            ->get(['organization_type', 'sector', 'effective_from'])
            ->map(fn (TenantProfileAssignment $row) => [
                'organization_type' => $row->organization_type,
                'sector' => $row->sector,
                'effective_from' => $row->effective_from->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }
}
