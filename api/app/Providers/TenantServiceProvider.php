<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CurrentTenant;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    /**
     * `scoped`, not `singleton`.
     *
     * Within an HTTP request the two are identical — resolved once, cached.
     * The difference is the queue worker, which is a long-running process
     * handling many tenants' jobs in turn. `QueueServiceProvider` hands the
     * Worker a `$resetScope` callback that calls `forgetScopedInstances()`, and
     * `Worker::daemon()` invokes it before reserving each job. A `scoped`
     * binding is therefore cleared between jobs; a `singleton` is not.
     *
     * That mattered because eight queued jobs and a queued listener call
     * `$currentTenant->set($tenant)`, and nothing in `app/` calls `forget()` at
     * all. Bound as a singleton, the next job inherited whichever tenant ran
     * before it, and `BelongsToTenant` scoped that job's queries to a tenant it
     * had never heard of instead of failing closed.
     * Measured: a webhook delivery row was written against the wrong tenant
     * (BASELINE §11c), and `Employee::count()` after a reset still returned the
     * previous job's rows.
     *
     * Fail-closed is the property the whole product rests on. It only holds
     * while "no tenant resolved" is actually reachable.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
    }
}
