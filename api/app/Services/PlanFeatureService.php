<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanFeature;
use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Enforces the per-tier feature list on `Plan.features` — previously read by
 * nothing but the pricing page, so every tenant had every feature regardless of
 * what it paid for. A Starter tenant (attendance, leave, employee_management)
 * could run payroll, register webhooks and read the audit log.
 *
 * The companion to {@see PlanLimitService}, which caps seat/branch/device
 * *counts*. This caps *capabilities*, and deliberately copies that class's
 * fail-open safeguards, because the failure mode they prevent is the same and
 * is severe: a gate that is wrong in the closed direction locks paying tenants
 * out of features they already use.
 *
 *  - **Trial tenants get everything.** `AuthService` puts every new tenant on
 *    Starter for a 6-month trial; gating evaluation to Starter's three features
 *    would make the trial useless to a prospect assessing payroll — the single
 *    most likely reason they are evaluating an Ethiopian HCM product at all.
 *  - **No subscription or no plan means unlimited** (fail-open), not zero. The
 *    demo tenant carries 150 employees and no subscription row.
 *  - **A plan with no `features` array is unlimited**, not empty. An older
 *    deployment seeded before the column existed must not lose access on
 *    upgrade.
 *
 * Read-only endpoints are deliberately NOT gated anywhere this is applied: a
 * tenant that downgrades keeps sight of the payroll it already ran. Only the
 * write paths that create new obligations are refused.
 */
class PlanFeatureService
{
    /**
     * Whether `$tenant` may use `$feature`.
     *
     * Returns true for every case where the answer is genuinely unknown — see
     * the class docblock. Ambiguity resolves to access, never to a lockout.
     */
    public function allows(Tenant $tenant, PlanFeature $feature): bool
    {
        if ($tenant->status === TenantStatus::TRIAL) {
            return true;
        }

        $plan = $tenant->subscription?->plan;

        if ($plan === null) {
            return true;
        }

        $features = $plan->features;

        // Distinguish "no feature list recorded" (unlimited) from "an empty
        // list was recorded" (nothing allowed): a NULL column arrives as null,
        // an empty JSON array as [], and `[]` is still an array so it falls
        // through to in_array() and refuses.
        //
        // `is_array` rather than `!== null` because the cast is not a guarantee.
        // Larastan resolves an `array` cast as `array{}|string` (the same
        // limitation phpstan-baseline.neon records for Tenant::$settings), and
        // a JSON column holding a scalar would decode to one — in which case
        // "no usable list" is the honest reading, and fail-open is what the
        // rest of this class does with that.
        if (! is_array($features)) {
            return true;
        }

        return in_array($feature->value, $features, true);
    }

    /**
     * Aborts with 403 — rendered as RFC-7807 by the handler in
     * bootstrap/app.php — when the tenant's plan does not include `$feature`.
     */
    public function assertAllows(Tenant $tenant, PlanFeature $feature): void
    {
        if ($this->allows($tenant, $feature)) {
            return;
        }

        abort(403, __($feature->upgradeMessageKey(), [
            'feature' => __('billing.features.'.$feature->value),
        ]));
    }
}
