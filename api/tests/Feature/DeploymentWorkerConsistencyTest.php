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
 * Every asset that starts a queue worker.
 *
 * One entry since the VPS decommission (2026-09-26). It held four:
 * `infrastructure/supervisor.conf`, `docker-compose.prod.yml` and
 * `docker-compose.lowmem.yml` were the VPS production path and are gone, along
 * with the third test in this file, which existed solely to keep those last two
 * marked as broken.
 *
 * `docker-compose.yml` is local development and stays. **It is now the only
 * asset this file checks**, so the union-of-queues assertion below is a weaker
 * guard than it was: on shared hosting the worker is
 * `POST /api/v1/cron/queue` -> `queue:work`, which is a route, not a file, and
 * nothing here reads it. `QueueHealth::QUEUES` is pinned by the last test and is
 * what actually protects the queue list now.
 */
const ETHR_WORKER_ASSETS = [
    'docker-compose.yml',
];

function ethrDeploymentAsset(string $relative): string
{
    return (string) file_get_contents(dirname(base_path()).'/'.$relative);
}

/**
 * The UNION of every `--queue=a,b,c` list in an asset, as a sorted set.
 *
 * A union rather than a single match because the VPS compose stack split
 * the work across two services (2026-09-22): `attendance,notifications,default`
 * on one and `exports` on the other. The correctness property is that every
 * dispatched queue is drained by SOMETHING in the file — not that one command
 * line names them all.
 */
function ethrWorkerQueues(string $contents, string $asset): array
{
    // `expect(bool)->toBeTrue($message)` rather than `toBeGreaterThan(0, $message)`:
    // this file's header records that Pest's `toContain` is VARIADIC, so its
    // second argument is a second needle rather than a message. `toBeTrue` is
    // one of the four documented there as taking a genuine message, so it is
    // used here instead of betting on another expectation's signature.
    expect(preg_match_all('/--queue=([a-z,]+)/', $contents, $m) > 0)->toBeTrue(
        "$asset does not pass --queue to any worker. A bare `queue:work` drains "
        .'only `default`, so three of this application\'s four queues would starve '
        .'while the worker looked healthy.'
    );

    $queues = [];

    foreach ($m[1] as $list) {
        $queues = array_merge($queues, explode(',', $list));
    }

    $queues = array_values(array_unique($queues));
    sort($queues);

    return $queues;
}

it('starts its workers with a command the application actually defines', function () {
    $lock = ethrDeploymentAsset('api/composer.lock');

    // Guard the guard: if Horizon is ever reinstalled deliberately, this test
    // should stop asserting its absence rather than silently keep passing for
    // the wrong reason.
    expect(str_contains($lock, 'laravel/horizon'))->toBeFalse(
        'laravel/horizon is back in composer.lock. Horizon was removed in cdf85d1 and '
        .'this test encodes that. If the reinstall is intended, rewrite this file; do not '
        .'delete it, because the defect it caught was invocations outliving the package.'
    );

    foreach (ETHR_WORKER_ASSETS as $asset) {
        $contents = ethrDeploymentAsset($asset);

        // Comments may name horizon — they explain what was fixed. An executable
        // line may not.
        $executable = implode("\n", array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')
        ));

        expect($executable)->not->toMatch(
            '/artisan horizon/',
            "$asset invokes `artisan horizon`, which is not a defined command — "
            .'laravel/horizon is absent from composer.lock. The worker exits immediately '
            .'and no queued job ever runs.'
        );

        expect($executable)->toMatch(
            '/artisan queue:work/',
            "$asset must start a queue worker. Every job in app/Jobs implements "
            .'ShouldQueue, so without one the asynchronous half of the product is dead.'
        );
    }
});

it('drains exactly the queues the application dispatches onto', function () {
    $expected = QueueHealth::QUEUES;
    sort($expected);

    foreach (ETHR_WORKER_ASSETS as $asset) {
        // Compared as a set: the ORDER on the command line is meaningful to
        // Laravel (earlier queues drain first, which is why `attendance` leads)
        // but it is a tuning choice, not a correctness one. Membership is the
        // correctness question — a queue missing here is a queue that fills
        // forever, and `QueueHealth` would report it only to whoever opens the
        // health endpoint.
        expect(ethrWorkerQueues(ethrDeploymentAsset($asset), $asset))->toBe(
            $expected,
            "$asset drains a different set of queues than the application dispatches "
            .'onto. QueueHealth::QUEUES is the authoritative list; add the queue in both '
            .'places or in neither.'
        );
    }
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
