<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Starts one process per action (tests/Concurrency/action-child.php), holds
 * them all at a barrier until every one has booted and connected, and releases
 * them together. The same method as AttendancePunchRaceTest, in a class so no
 * global function name can collide with that file's.
 */
final class IdempotencyRace
{
    /** The scratch database, or a refusal: fixtures here are committed, never rolled back. */
    public static function guard(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || preg_match('/concurrency|scratch|suite/', $database) !== 1
            || config('cache.default') !== 'database') {
            throw new RuntimeException(
                "The concurrency suite needs MySQL/MariaDB, a scratch database and the database cache store; got [{$database}]. Run it with ./scripts/gates.sh concurrency."
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $jobs  each with an `action` and what that action needs
     * @return list<array{exit: int, job: array<string, mixed>, out: array<string, mixed>, err: string}>
     */
    public static function run(array $jobs): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethr-idem-'.Str::lower(Str::random(12));
        mkdir($barrier, 0777, true);

        $connection = array_intersect_key(
            config('database.connections.'.config('database.default')),
            array_flip(['host', 'port', 'database', 'username', 'password']),
        );

        $children = [];
        foreach ($jobs as $i => $job) {
            $payload = base64_encode(json_encode([
                ...$job,
                'id' => (string) $i,
                'barrier' => $barrier,
                'connection' => $connection,
            ], JSON_THROW_ON_ERROR));

            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=-1', dirname(__DIR__).DIRECTORY_SEPARATOR.'Concurrency'.DIRECTORY_SEPARATOR.'action-child.php', $payload],
                [1 => ['file', "{$barrier}/out-{$i}", 'w'], 2 => ['file', "{$barrier}/err-{$i}", 'w']],
                $pipes,
                dirname(__DIR__, 2),
            );
            if ($process === false) {
                throw new RuntimeException('Could not start a child process.');
            }
            $children[$i] = ['process' => $process, 'job' => $job];
        }

        $deadline = microtime(true) + 240;
        while (count(glob("{$barrier}/ready-*") ?: []) < count($children)) {
            foreach ($children as $i => $child) {
                $status = proc_get_status($child['process']);
                if (! $status['running']) {
                    throw new RuntimeException('A child stopped before the barrier: '.file_get_contents("{$barrier}/err-{$i}").file_get_contents("{$barrier}/out-{$i}"));
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The child processes did not all start within four minutes.');
            }
            usleep(20000);
        }

        // Written whole, then renamed: a child must never read half a number.
        file_put_contents("{$barrier}/go.tmp", sprintf('%.6F', microtime(true) + 0.5));
        rename("{$barrier}/go.tmp", "{$barrier}/go");

        $results = [];
        $deadline = microtime(true) + 300;
        foreach ($children as $i => $child) {
            while (true) {
                $status = proc_get_status($child['process']);
                if (! $status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($child['process']);
                    throw new RuntimeException('A child process did not finish within five minutes.');
                }
                usleep(10000);
            }
            proc_close($child['process']);

            $results[] = [
                'exit' => $exit,
                'job' => $child['job'],
                'out' => json_decode((string) file_get_contents("{$barrier}/out-{$i}"), true) ?? [],
                'err' => trim((string) file_get_contents("{$barrier}/err-{$i}")),
            ];
        }

        array_map('unlink', glob("{$barrier}/*") ?: []);
        rmdir($barrier);

        return $results;
    }

    /** What went wrong in the children, for a failure message. */
    public static function failures(array $results): array
    {
        return array_values(array_filter(array_map(
            fn (array $r) => $r['exit'] === 0 ? null : "{$r['job']['action']}: exit {$r['exit']} ".json_encode($r['out']).' '.$r['err'],
            $results,
        )));
    }

    /** @return array<string, mixed> */
    public static function http(string $method, string $url, int $userId, array $body = [], ?string $warmUrl = null): array
    {
        return ['action' => 'http', 'method' => $method, 'url' => $url, 'user_id' => $userId, 'body' => $body, 'warm_url' => $warmUrl];
    }
}
