<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Enums\Feature;
use App\Enums\FeatureOverrideMode;
use App\Enums\FeatureState;
use App\Models\AuditLog;
use App\Models\PlatformFeatureState;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The platform admin's writes to the feature switches, each audited with its
 * reason (owner decision D3: a reason, behind the console's MFA session, and
 * no second code). Only the console calls this; {@see FeatureGate} reads.
 *
 * Every override query drops the tenant scope: the console runs on the
 * platform host, where no tenant is resolved and the fail-closed scope would
 * find nothing, so an update would insert a second row. Each one states
 * `tenant_id` from the organisation in the route, except the per-feature
 * counts, which are across every organisation by intent.
 */
final class FeatureControls
{
    public function __construct(private readonly FeatureGate $gate) {}

    public function setPlatformState(Feature $feature, FeatureState $state, string $reason, User $actor): PlatformFeatureState
    {
        $row = PlatformFeatureState::query()->firstOrNew(['key' => $feature->value]);
        $from = $row->exists ? $row->state : $feature->defaultState();

        $row->fill([
            'state' => $state,
            'reason' => $reason,
            'changed_by_user_id' => $actor->id,
        ])->save();

        AuditLog::record('platform.feature_state_changed', $row, [
            'feature' => $feature->value,
            'from' => $from->value,
            'to' => $state->value,
            'reason' => $reason,
        ]);

        $this->gate->flush();

        return $row;
    }

    public function setOverride(
        Tenant $tenant,
        Feature $feature,
        FeatureOverrideMode $mode,
        string $reason,
        ?CarbonInterface $expiresAt,
        User $actor,
    ): void {
        $row = $this->override($tenant, $feature)
            ?? new TenantFeatureOverride(['tenant_id' => $tenant->id, 'key' => $feature->value]);
        $from = $row->exists ? $row->mode->value : null;

        $row->fill([
            'mode' => $mode,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'changed_by_user_id' => $actor->id,
        ])->save();

        AuditLog::record('admin.tenant.feature_override_set', $tenant, [
            'feature' => $feature->value,
            'from' => $from,
            'to' => $mode->value,
            'expires_at' => $expiresAt?->toIso8601String(),
            'reason' => $reason,
        ]);

        $this->gate->flush();
    }

    /** False when the organisation had no override for the feature. */
    public function removeOverride(Tenant $tenant, Feature $feature, string $reason): bool
    {
        $row = $this->override($tenant, $feature);

        if ($row === null) {
            return false;
        }

        $row->delete();

        AuditLog::record('admin.tenant.feature_override_removed', $tenant, [
            'feature' => $feature->value,
            'was' => $row->mode->value,
            'reason' => $reason,
        ]);

        $this->gate->flush();

        return true;
    }

    /**
     * The organisation's overrides by key, expired ones included: the console
     * shows an expired grant as history rather than hiding why it ended.
     *
     * @return array<string, TenantFeatureOverride>
     */
    public function overridesOf(Tenant $tenant): array
    {
        return TenantFeatureOverride::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('key')
            ->all();
    }

    private function override(Tenant $tenant, Feature $feature): ?TenantFeatureOverride
    {
        return $this->overridesOf($tenant)[$feature->value] ?? null;
    }

    /**
     * How many organisations hold an unexpired grant or revoke of each
     * feature, across every organisation: the console's overview.
     *
     * @return array<string, array{grants: int, revokes: int}>
     */
    public function overrideCounts(): array
    {
        $counts = [];

        $rows = TenantFeatureOverride::query()
            ->withoutGlobalScope('tenant')
            ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->select(['key', 'mode'])
            ->selectRaw('count(*) as total')
            ->groupBy('key', 'mode')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[$row->key] ??= ['grants' => 0, 'revokes' => 0];
            $counts[$row->key][$row->mode === FeatureOverrideMode::Grant->value ? 'grants' : 'revokes'] = (int) $row->total;
        }

        return $counts;
    }
}
