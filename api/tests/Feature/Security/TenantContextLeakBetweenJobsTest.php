<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;

/**
 * Tenant context must not survive from one queued job into the next.
 *
 * `BelongsToTenant` is fail-closed: with no tenant resolved it applies
 * `whereRaw('0 = 1')`, so absence of context yields no rows rather than all of
 * them. That property is the reason this product is safe by default — but it
 * only holds while "no tenant resolved" is actually reachable.
 *
 * A queue worker is a long-running process handling many tenants' jobs in turn.
 * Nine jobs call `$currentTenant->set($tenant)`; **none** calls `forget()`. So
 * whether the tenth job starts clean depends entirely on how `CurrentTenant` is
 * bound in the container:
 *
 *   - `singleton` is resolved once and kept for the life of the process.
 *   - `scoped` is flushed between jobs by the framework itself —
 *     `Illuminate\Queue\QueueServiceProvider` hands the Worker a `$resetScope`
 *     callback that calls `$app->forgetScopedInstances()`, and
 *     `Worker::daemon()` invokes it at the top of every loop iteration, before
 *     reserving the next job.
 *
 * These tests call that same framework method directly, so they exercise the
 * real reset rather than a simulation of one. This was found while auditing the
 * `withoutGlobalScope` call sites (BASELINE §11c): a webhook delivery row was
 * measurably filed against whichever tenant happened to be resolved last.
 */
it('forgets the tenant when the worker resets scope between jobs', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'first-job']);

    app(CurrentTenant::class)->set($tenant);
    expect(app(CurrentTenant::class)->resolved())->toBeTrue();

    // Exactly what Worker::daemon() does before it reserves the next job.
    app()->forgetScopedInstances();

    expect(app(CurrentTenant::class)->resolved())->toBeFalse();
});

it('fails closed for the next job rather than reading the previous tenant', function () {
    $first = Tenant::factory()->create(['subdomain' => 'first-job']);

    app(CurrentTenant::class)->set($first);
    Employee::factory()->create(['tenant_id' => $first->id]);

    expect(Employee::count())->toBe(1);

    app()->forgetScopedInstances();

    // The next job has not resolved a tenant yet. It must see nothing — not the
    // previous job's rows. This is the whole of `BelongsToTenant`'s guarantee,
    // and a leaked context is what quietly removes it.
    expect(Employee::count())->toBe(0);
});
