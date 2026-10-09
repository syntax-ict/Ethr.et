<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Requests\Maintenance\CreatePlatformAdminRequest;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the install and release steps over HTTP, because nothing on the
 * production host can run artisan (OWNER-DECISION-MAINTENANCE-ENDPOINT.md).
 *
 * A FIXED list, never a command name taken from the request: each task maps
 * to the artisan calls PLESK-GO-LIVE.md prescribes, with their arguments
 * written here. Kept out of the published API contract, like the cron routes:
 * the frontend never calls these, and an OpenAPI document would advertise
 * routes that 404 when unconfigured precisely so they are not advertised.
 */
class MaintenanceController
{
    /** @var array<string, list<array{0: string, 1: array<string, mixed>}>> */
    private const TASKS = [
        'migrate' => [['migrate', ['--force' => true]]],
        'seed' => [['db:seed', ['--class' => 'ProductionSeeder', '--force' => true]]],
        'optimize' => [['config:cache', []], ['route:cache', []], ['view:cache', []]],
        // After an edit to .env: a cached config ignores the file until cleared.
        'clear' => [['optimize:clear', []]],
    ];

    /**
     * One of the fixed tasks: `migrate`, `seed`, `optimize` or `clear`.
     */
    #[ExcludeRouteFromDocs]
    public function run(string $task): JsonResponse
    {
        abort_unless(array_key_exists($task, self::TASKS), 404);

        return $this->exclusively($task, function () use ($task): array {
            $steps = [];

            foreach (self::TASKS[$task] as [$command, $arguments]) {
                $exit = Artisan::call($command, $arguments);
                $steps[] = ['command' => $command, 'exit_code' => $exit, 'output' => $this->tail(Artisan::output())];

                if ($exit !== 0) {
                    break;
                }
            }

            return $steps;
        });
    }

    /**
     * Generates APP_KEY, once. Refused when a key is already set: a second
     * key makes every encrypted column (TIN, national ID, bank details, MFA
     * secrets) unreadable, so this route can only ever fill an empty one.
     */
    #[ExcludeRouteFromDocs]
    public function key(): JsonResponse
    {
        if ((string) config('app.key') !== '') {
            return response()->json([
                'task' => 'key',
                'status' => 'refused',
                'detail' => 'APP_KEY is already set. It is never replaced from here.',
            ], 409);
        }

        return $this->exclusively('key', function (): array {
            $exit = Artisan::call('key:generate', ['--force' => true]);

            // key:generate prints the key; it stays out of the response.
            return [['command' => 'key:generate', 'exit_code' => $exit, 'output' => $exit === 0 ? 'Application key set.' : 'key:generate failed.']];
        });
    }

    /**
     * `ethr:create-admin` for the platform super admin. The password travels
     * in the body over HTTPS and is never echoed or logged.
     */
    #[ExcludeRouteFromDocs]
    public function createAdmin(CreatePlatformAdminRequest $request): JsonResponse
    {
        return $this->exclusively('create-admin', function () use ($request): array {
            $exit = Artisan::call('ethr:create-admin', [
                '--email' => $request->validated('email'),
                '--password' => $request->validated('password'),
                '--force' => true,
                '--no-interaction' => true,
            ]);

            return [['command' => 'ethr:create-admin', 'exit_code' => $exit, 'output' => $this->tail(Artisan::output())]];
        });
    }

    /**
     * One maintenance task at a time, under a lock in the file cache (the
     * database one may not have its table yet). 409 rather than a second run.
     *
     * @param  callable(): list<array{command: string, exit_code: int, output: string}>  $work
     */
    private function exclusively(string $task, callable $work): JsonResponse
    {
        $store = Cache::store((string) config('maintenance.cache_store', 'file'))->getStore();
        abort_unless($store instanceof LockProvider, 500, 'The maintenance cache store cannot hold a lock.');

        $lock = $store->lock('maintenance:run', (int) config('maintenance.lock_seconds', 300));

        if (! $lock->get()) {
            return response()->json(['task' => $task, 'status' => 'already-running'], 409);
        }

        $startedAt = microtime(true);

        try {
            $steps = $work();
        } finally {
            $lock->release();
        }

        $failed = collect($steps)->contains(fn (array $step) => $step['exit_code'] !== 0);

        logger()->info('Maintenance task ran.', [
            'task' => $task,
            'status' => $failed ? 'failed' : 'ok',
            'exit_codes' => array_column($steps, 'exit_code'),
        ]);

        return response()->json([
            'task' => $task,
            'status' => $failed ? 'failed' : 'ok',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'steps' => $steps,
        ], $failed ? 500 : 200);
    }

    /** Artisan output is for the operator reading the response, so cap it. */
    private function tail(string $output): string
    {
        $output = trim($output);

        return strlen($output) > 3000 ? '…'.substr($output, -3000) : $output;
    }
}
