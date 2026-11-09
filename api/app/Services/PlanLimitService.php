<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

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
     * How long the seat lock is held at most. An import commit of a few
     * hundred rows, logins included, finishes well inside it; a lock that
     * expired mid-commit would let a second writer count too early.
     */
    public const LOCK_SECONDS = 120;

    /** How long a second writer waits for the first before it is refused. */
    public const LOCK_WAIT_SECONDS = 20;

    public function __construct(private readonly int $lockWaitSeconds = self::LOCK_WAIT_SECONDS) {}

    /**
     * Checks the cap and runs `$create` under one lock per organisation and
     * resource, so the count it is checked against cannot move until the
     * create is done.
     *
     * assertCanAdd() alone counted, then the caller created: two import
     * commits sent together each counted the same headcount, each passed, and
     * both landed past the plan (review 2026-11-09). The second now waits for
     * the first and counts what it created, as PayrollEngine::reserve() does
     * for overlapping payroll runs. `$create` must commit before it returns:
     * a transaction still open when the lock is released hides its rows from
     * the next count.
     *
     * A writer that cannot get the lock in time is refused with a 409, not
     * let through and not a 500.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T
     */
    public function withinLimit(Tenant $tenant, string $resource, int $count, Closure $create): mixed
    {
        return $this->serialised($tenant, $resource, function () use ($tenant, $resource, $count, $create): mixed {
            $this->assertCanAdd($tenant, $resource, $count);

            return $create();
        });
    }

    /**
     * The lock alone, for a caller that checks the cap itself inside `$work`
     * because it may return without creating anything (a replayed hire).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function serialised(Tenant $tenant, string $resource, Closure $work): mixed
    {
        $lock = $this->lock($tenant, $resource);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * The seat lock, held, for a caller that releases it in its own `finally`:
     * a controller whose response type is read from the statements between,
     * which the API contract generator cannot follow through a closure.
     */
    public function lock(Tenant $tenant, string $resource): Lock
    {
        $lock = Cache::lock(self::lockKey($tenant, $resource), self::LOCK_SECONDS);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException) {
            abort(409, __('billing.limit_check_busy'));
        }

        return $lock;
    }

    public static function lockKey(Tenant $tenant, string $resource): string
    {
        return "plan-limit:{$resource}:{$tenant->id}";
    }

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

        // The limit and how to count against it, from one match: two
        // separate matches had to agree on the resource names, and the
        // second could not tell a capped resource from an unknown one.
        [$limit, $count] = match ($resource) {
            'employees' => [$plan->max_employees, fn (): int => $tenant->employees()->count()],
            'branches' => [$plan->max_branches, fn (): int => $tenant->branches()->count()],
            'devices' => [$plan->max_devices, fn (): int => $tenant->devices()->count()],
            default => [null, fn (): int => 0],
        };

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $count());
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
