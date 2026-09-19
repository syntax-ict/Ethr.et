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
 */
function ethrDeploymentAsset(string $relative): string
{
    return (string) file_get_contents(dirname(base_path()).'/'.$relative);
}

/** The `--queue=a,b,c` list from a worker command line, as a sorted set. */
function ethrWorkerQueues(string $contents, string $asset): array
{
    expect(preg_match('/--queue=([a-z,]+)/', $contents, $m))->toBe(
        1,
        "$asset does not pass --queue to its worker. A bare `queue:work` drains "
        .'only `default`, so three of this application\'s four queues would starve '
        .'while the worker looked healthy.'
    );

    $queues = explode(',', $m[1]);
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

    foreach (['infrastructure/supervisor.conf', 'docker-compose.yml'] as $asset) {
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

    foreach (['infrastructure/supervisor.conf', 'docker-compose.yml'] as $asset) {
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

it('keeps the unfixed worker stacks marked as broken', function () {
    // docker-compose.prod.yml and .lowmem.yml still invoke Horizon. They are NOT
    // fixed, deliberately: they partition work three ways over `attendance`,
    // `devices`, `sync`, `notifications` and `payroll`, and three of those queues
    // do not exist — the application dispatches onto default, attendance,
    // notifications and exports only. Even with Horizon present that topology
    // leaves `default` and `exports` undrained. Choosing the replacement is a
    // deployment-architecture decision for the owner.
    //
    // What must not happen is the banner being removed while the breakage stays,
    // which would return these files to looking runnable.
    foreach (['docker-compose.prod.yml', 'docker-compose.lowmem.yml'] as $asset) {
        $contents = ethrDeploymentAsset($asset);

        if (! str_contains($contents, 'artisan horizon')) {
            // Fixed since this test was written — nothing left to mark.
            continue;
        }

        expect($contents)->toContain(
            'BROKEN AS OF 2026-09-19',
            "$asset still invokes `artisan horizon` but no longer carries the banner "
            .'saying so. Either fix the stack or keep the warning: a compose file that '
            .'reads as runnable and starts no worker is how this went unnoticed once.'
        );
    }
});
