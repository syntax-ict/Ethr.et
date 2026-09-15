<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * The queue's `retry_after` must exceed the longest job timeout.
 *
 * Laravel releases a reserved job back onto the queue after `retry_after`
 * seconds, on the assumption that a worker holding it that long has died. If a
 * job's own timeout is *longer* than that window, the assumption is wrong: the
 * job is still running, and a second worker picks it up and runs it again.
 *
 * ETHR shipped with `retry_after` at 90s against timeouts of 300-900s, so five
 * jobs could execute twice - duplicate leave accrual, duplicate year-end
 * carry-forward, duplicate tenant backups, duplicate scheduled reports and
 * digests. Nothing errors when this happens. Both runs succeed; the data is
 * just wrong afterwards.
 *
 * This is asserted rather than simulated deliberately. Reproducing the race
 * needs two workers, real elapsed time and a job that sleeps past the window -
 * slow, flaky, and it would prove the framework's behaviour rather than this
 * application's configuration. The configuration is the part that was wrong,
 * so the configuration is what is checked.
 *
 * Master plan §19: "Critical invariant: retry_after > maximum expected job
 * runtime. This MUST be tested."
 */

/** @return array<class-string, int> job class => declared timeout */
function declaredJobTimeouts(): array
{
    $timeouts = [];

    foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
        $class = 'App\\Jobs\\'.Str::before(basename($file), '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->hasProperty('timeout')) {
            continue;
        }

        $timeouts[$class] = (int) $reflection->getDefaultProperties()['timeout'];
    }

    return $timeouts;
}

it('gives every job an explicit timeout', function () {
    $files = glob(app_path('Jobs/*.php')) ?: [];
    $declared = declaredJobTimeouts();

    $missing = [];
    foreach ($files as $file) {
        $class = 'App\\Jobs\\'.Str::before(basename($file), '.php');
        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && ! isset($declared[$class])) {
            $missing[] = class_basename($class);
        }
    }

    // A job with no declared timeout silently inherits the worker's --timeout
    // (60s by default). That is not necessarily wrong, but it makes the
    // invariant below uncomputable: you cannot compare retry_after against a
    // number nobody wrote down. Five jobs were in this state, including
    // GenerateMonthlyInvoicesJob, which iterates every active subscription.
    expect($missing)->toBe([], 'These jobs declare no $timeout: '.implode(', ', $missing));
});

it('sets retry_after above the longest job timeout on every database-driven queue', function () {
    $timeouts = declaredJobTimeouts();

    expect($timeouts)->not->toBeEmpty('No jobs were discovered — the reflection above is broken, not the config.');

    $longest = max($timeouts);
    $slowest = array_search($longest, $timeouts, true);

    foreach (config('queue.connections') as $name => $connection) {
        if (($connection['driver'] ?? null) !== 'database') {
            continue;
        }

        expect($connection['retry_after'] ?? 0)->toBeGreaterThan(
            $longest,
            sprintf(
                'queue.connections.%s.retry_after is %d, but %s declares a timeout of %ds. '
                .'A job still running when retry_after elapses is re-reserved and executed twice.',
                $name,
                $connection['retry_after'] ?? 0,
                class_basename((string) $slowest),
                $longest,
            ),
        );
    }
});

it('keeps the shipped default safe, not just the configured value', function () {
    // config/queue.php reads DB_QUEUE_RETRY_AFTER with a fallback. The fallback
    // is what a deployment gets when nobody sets the variable - which is the
    // situation that produced the original bug - so the default is asserted
    // directly rather than through config(), which the test environment may
    // have overridden.
    $source = file_get_contents(config_path('queue.php'));

    preg_match("/env\('DB_QUEUE_RETRY_AFTER',\s*(\d+)\)/", (string) $source, $matches);

    expect($matches[1] ?? null)->not->toBeNull('DB_QUEUE_RETRY_AFTER default not found in config/queue.php');
    expect((int) $matches[1])->toBeGreaterThan(max(declaredJobTimeouts()));
});
