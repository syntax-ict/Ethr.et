<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Enforces the seat/branch/device caps on `Plan` — previously read by nothing
 * but the pricing page, so any tenant had unlimited headcount regardless of
 * plan tier. Deliberately narrow: this is usage-count enforcement only. The
 * `Plan.features` gate (payroll/reports/webhooks/etc per tier) is a separate,
 * larger change and is not touched here.
 *
 * Two things keep this from bricking anyone:
 *  - Trial tenants are never capped. `AuthService` puts every new tenant on
 *    Starter (max 10 employees) for a 6-month trial; capping evaluation at
 *    Starter's limits would make the trial useless for a prospect with a
 *    real, larger roster to try out.
 *  - A tenant with no subscription/plan at all is treated as unlimited
 *    (fail-open), not zero. The demo tenant carries 150 employees and no
 *    subscription row — enforcement must not touch it.
 *  - Only *new* creates are blocked. An already-over-limit tenant (grandfathered
 *    in, or one that downgraded) keeps everything it already has; nothing here
 *    ever deletes or disables an existing record.
 */
class PlanLimitService
{
    /**
     * Capacity left for `$resource`, or null if this tenant/resource
     * combination is not capped at all (trial, or no plan on record).
     */
    public function remaining(Tenant $tenant, string $resource): ?int
    {
        if ($tenant->status === TenantStatus::TRIAL) {
            return null;
        }

        $plan = $tenant->subscription?->plan;
        if ($plan === null) {
            return null;
        }

        $limit = match ($resource) {
            'employees' => $plan->max_employees,
            'branches' => $plan->max_branches,
            'devices' => $plan->max_devices,
            default => null,
        };

        if ($limit === null) {
            return null;
        }

        $current = match ($resource) {
            'employees' => $tenant->employees()->count(),
            'branches' => $tenant->branches()->count(),
            'devices' => $tenant->devices()->count(),
            default => 0,
        };

        return max(0, $limit - $current);
    }

    /**
     * Aborts with a 403 (rendered as RFC-7807 by the global HttpException
     * handler in bootstrap/app.php) if adding `$count` more of `$resource`
     * would exceed the tenant's plan.
     */
    public function assertCanAdd(Tenant $tenant, string $resource, int $count = 1): void
    {
        $remaining = $this->remaining($tenant, $resource);

        if ($remaining === null || $remaining >= $count) {
            return;
        }

        $langKey = match ($resource) {
            'employees' => 'billing.employee_limit_reached',
            'branches' => 'billing.branch_limit_reached',
            'devices' => 'billing.device_limit_reached',
            default => 'billing.employee_limit_reached',
        };

        abort(403, __($langKey));
    }
}
