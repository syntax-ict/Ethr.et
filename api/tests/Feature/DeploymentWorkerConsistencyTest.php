<?php

declare(strict_types=1);

use App\Services\Observability\QueueHealth;

/**
 * Every deployment asset in this repository started its queue worker with
 * `php artisan horizon`, and Horizon was removed in cdf85d1.
 *
 * Measured 2026-09-19: `laravel/horizon` appears **zero** times in
 * `api/composer.lock`, there is no `api/config/horizon.php`, and no command in
 * `api/app/` carries that signature. So the command does not exist, and:
 *
 * - `infrastructure/supervisor.conf` ran it as `[program:ethr-horizon]` in the
 *   autostart group. Supervisor retries `startretries=5` times and then gives
 *   up, leaving the VPS with no worker while every other process looks healthy.
 * - `docker-compose.yml`'s `worker` service ran it under
 *   `restart: unless-stopped`, which turns an immediate exit into a crashloop.
 *
 * Both are now `queue:work`. The root `CLAUDE.md` says *"without the queue
 * worker no job ever runs"* — and the worker it told you to start was the
 * broken one, which is why nothing caught this: the failure is in the process
 * that exists to make other failures visible.
 *
 * All 16 jobs in `app/Jobs` implement `ShouldQueue`, as do two listeners, so a
 * missing worker is not a degraded mode — it is the entire asynchronous half of
 * the product.
 *
 * These assertions read files and need no database, network or container.
 *
 * One Pest trap, hit by this file on its first CI run: **`toContain` is
 * variadic.** `toContain($needle, $message)` searches for BOTH strings, so the
 * failure message becomes a second needle and the assertion fails even when the
 * real needle is present. Use `expect(str_contains(...))->toBeTrue($message)`.
 * `toBe`, `toMatch`, `toBeTrue` and `toBeFalse` all take a genuine message.
 */
/**
 * The one worker ETHR has: `POST /api/v1/cron/queue` -> CronRunController::queue()
 * -> `queue:work`, driven by the GitHub Actions caller (.github/workflows/cron.yml).
 *
 * Until 2026-09-30 this file read `docker-compose.yml`'s `worker` service instead,
 * and its own comment admitted that was the weaker guard: the production worker is
 * a route, not a file, and nothing read it. The Docker development stack was
 * removed that day, so the guard now points at the code that actually runs on the
 * host. (Before the VPS decommission of 2026-09-26 it checked four assets:
 * infrastructure/supervisor.conf and three compose files.)
 */
function ethrCronController(): string
{
    return (string) file_get_contents(app_path('Http/Controllers/Api/V1/Cron/CronRunController.php'));
}

it('starts its worker with a command the application actually defines', function () {
    $lock = (string) file_get_contents(base_path('composer.lock'));

    // Guard the guard: if Horizon is ever reinstalled deliberately, this test
    // should stop asserting its absence rather than silently keep passing for
    // the wrong reason.
    expect(str_contains($lock, 'laravel/horizon'))->toBeFalse(
        'laravel/horizon is back in composer.lock. Horizon was removed in cdf85d1 and '
        .'this test encodes that. If the reinstall is intended, rewrite this file; do not '
        .'delete it, because the defect it caught was invocations outliving the package.'
    );

    $controller = ethrCronController();

    expect(str_contains($controller, 'horizon'))->toBeFalse(
        'CronRunController mentions horizon, which is not a defined command.'
    );

    expect(str_contains($controller, "Artisan::call('queue:work'"))->toBeTrue(
        'CronRunController must start a queue worker. Every job in app/Jobs implements '
        .'ShouldQueue, so without one the asynchronous half of the product is dead.'
    );
});

it('drains exactly the queues the application dispatches onto', function () {
    $controller = ethrCronController();

    // Built from the constant rather than a literal list, so it cannot drift from
    // it. A literal here is the defect this file exists to catch: a partial
    // --queue drains only what it names and starves the rest while looking healthy.
    expect(str_contains($controller, "'--queue' => implode(',', QueueHealth::QUEUES)"))->toBeTrue(
        'CronRunController no longer builds --queue from QueueHealth::QUEUES. A worker '
        .'that names its own queues drifts from the ones the code dispatches onto.'
    );

    // Shared hosting runs no supervisor: the process must exit, never linger.
    expect(str_contains($controller, "'--stop-when-empty' => true"))->toBeTrue(
        'The cron-driven worker must pass --stop-when-empty; a daemon on shared hosting '
        .'outlives the HTTP request that started it.'
    );
});

it('keeps QueueHealth::QUEUES equal to the queues the code dispatches onto', function () {
    // This closes the loop. The test above pins the worker's --queue list to
    // QueueHealth::QUEUES; this one pins QueueHealth::QUEUES to the code. Together
    // they guarantee the worker drains everything that is dispatched.
    //
    // Without it, adding `->onQueue('payroll')` to a job would silently create a
    // queue nothing drains: the worker line would still match the constant, and
    // the jobs would pile up until someone opened the health endpoint. That is
    // not hypothetical — docs/CLAUDE.md's queue-failure table assigned recovery
    // policies to `payroll` and `devices` queues for months, and neither has ever
    // existed.
    $sources = '';

    foreach ([
        dirname(base_path()).'/api/app',
        dirname(base_path()).'/api/routes',
    ] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources .= (string) file_get_contents($file->getPathname());
            }
        }
    }

    // `default` is implicit: a job dispatched without onQueue() lands there, and
    // several do.
    $found = ['default'];

    preg_match_all("/->onQueue\('([a-z_]+)'\)/", $sources, $m);
    $found = array_merge($found, $m[1]);

    // Schedule::job(new SomeJob, 'queue') — the queue is the second argument.
    preg_match_all("/Schedule::job\(\s*new\s+\w+\s*,\s*'([a-z_]+)'/", $sources, $m);
    $found = array_merge($found, $m[1]);

    $found = array_values(array_unique($found));
    sort($found);

    $declared = QueueHealth::QUEUES;
    sort($declared);

    expect($found)->toBe(
        $declared,
        'The queues the code dispatches onto ('.implode(', ', $found).') differ from '
        .'QueueHealth::QUEUES ('.implode(', ', $declared).'). A queue in the code but not '
        .'the constant is drained by nothing; a queue in the constant but not the code is '
        .'a worker argument for work that never arrives. Update both, and the recovery '
        .'table in docs/CLAUDE.md with them.'
    );
});
