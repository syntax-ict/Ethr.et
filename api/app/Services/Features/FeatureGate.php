<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Enums\Feature;
use App\Enums\FeatureOverrideMode;
use App\Enums\FeatureReason;
use App\Enums\FeatureState;
use App\Enums\PlanFeature;
use App\Models\PlatformFeatureState;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Services\PlanFeatureService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The one place a feature's availability is decided: platform state, then the
 * organisation's grant or revoke, then its plan. Every check goes through here,
 * route middleware and `/auth/me` alike, so the screen and the API cannot
 * disagree. Order and reasons: docs/FEATURE-CONTROL-CENTER-DESIGN.md §3.
 *
 * Bound `scoped` beside CurrentTenant, for the same reason: what it loaded for
 * one request or queued job must not carry into the next. Within one, the
 * platform states and each organisation's overrides are read once.
 *
 * {@see PlanFeatureService} is unchanged and is the last step. Its fail-open
 * rules (trial, no plan, no feature list) still decide whatever the platform
 * admin has not.
 */
final class FeatureGate
{
    /** @var array<string, FeatureState>|null */
    private ?array $platform = null;

    /** @var array<int, array<string, FeatureOverrideMode>> */
    private array $overrides = [];

    private bool $warned = false;

    public function __construct(private readonly PlanFeatureService $plans) {}

    public function decide(Tenant $tenant, Feature $feature): FeatureDecision
    {
        $state = $this->platformStates()[$feature->value] ?? $feature->defaultState();
        $override = $this->overridesFor($tenant)[$feature->value] ?? null;

        if ($state === FeatureState::Disabled) {
            return FeatureDecision::off(FeatureReason::PlatformDisabled);
        }

        if ($override === FeatureOverrideMode::Revoke) {
            return FeatureDecision::off(FeatureReason::Revoked);
        }

        if ($override === FeatureOverrideMode::Grant) {
            return FeatureDecision::on(FeatureReason::Granted, $state !== FeatureState::Hidden);
        }

        if ($state === FeatureState::Restricted) {
            return FeatureDecision::off(FeatureReason::NotInRollout);
        }

        if (! $this->plans->allows($tenant, $feature->planFeature())) {
            return FeatureDecision::off(FeatureReason::Plan);
        }

        return FeatureDecision::on(FeatureReason::Enabled, $state !== FeatureState::Hidden);
    }

    public function allows(Tenant $tenant, Feature $feature): bool
    {
        return $this->decide($tenant, $feature)->on;
    }

    /** Forgets what this request read, after it changed a switch itself. */
    public function flush(): void
    {
        $this->platform = null;
        $this->overrides = [];
    }

    /**
     * Every feature's decision, by key, for the screen.
     *
     * @return array<string, array{on: bool, visible: bool, reason: string}>
     */
    public function decisionsFor(Tenant $tenant): array
    {
        $decisions = [];

        foreach (Feature::cases() as $feature) {
            $decisions[$feature->value] = $this->decide($tenant, $feature)->toArray();
        }

        return $decisions;
    }

    /**
     * The plan features that are on, or null when every one is: the shape
     * `/auth/me` has sent as `plan_features` since audit N66, now derived from
     * the whole decision so a platform switch reaches screens still reading it.
     * With no platform rows and no overrides it equals
     * {@see PlanFeatureService::enabledFor()}.
     *
     * @return list<string>|null
     */
    public function enabledPlanFeatures(Tenant $tenant): ?array
    {
        $tenantFacing = PlanFeature::tenantFacing();

        $enabled = array_values(array_filter(
            $tenantFacing,
            fn (PlanFeature $feature): bool => $this->allows($tenant, Feature::fromPlanFeature($feature)),
        ));

        return count($enabled) === count($tenantFacing)
            ? null
            : array_map(fn (PlanFeature $feature): string => $feature->value, $enabled);
    }

    /** @return array<string, FeatureState> */
    private function platformStates(): array
    {
        return $this->platform ??= $this->readOrDefault(
            static fn (): array => PlatformFeatureState::query()
                ->get(['key', 'state'])
                ->mapWithKeys(static fn (PlatformFeatureState $row): array => [$row->key => $row->state])
                ->all(),
        );
    }

    /**
     * The organisation's unexpired overrides, by key.
     *
     * The scope is dropped and `tenant_id` stated from the tenant in hand, on
     * the same chain: the platform console asks about organisations other than
     * the resolved one, and a queued job may have none resolved, where the
     * fail-closed scope would hide every revoke and so turn a revoked feature
     * back on.
     *
     * @return array<string, FeatureOverrideMode>
     */
    private function overridesFor(Tenant $tenant): array
    {
        return $this->overrides[$tenant->id] ??= $this->readOrDefault(
            static fn (): array => TenantFeatureOverride::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get(['key', 'mode'])
                ->mapWithKeys(static fn (TenantFeatureOverride $row): array => [$row->key => $row->mode])
                ->all(),
        );
    }

    /**
     * Runs a read, and answers "no rows" when the tables cannot be read.
     *
     * Not hypothetical: on the host the code goes live before `migrate` runs,
     * so for a few minutes each release that adds these tables reads tables
     * that do not exist yet. No rows means the catalogue defaults and the plan,
     * which is exactly what was answered before the control center existed.
     *
     * @template T of array
     *
     * @param  callable(): T  $read
     * @return T|array{}
     */
    private function readOrDefault(callable $read): array
    {
        try {
            return $read();
        } catch (QueryException $e) {
            if (! $this->warned) {
                $this->warned = true;
                Log::warning('Feature control tables unreadable; using catalogue defaults.', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }
}
