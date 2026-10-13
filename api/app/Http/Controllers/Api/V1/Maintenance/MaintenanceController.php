<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Requests\Maintenance\CreatePlatformAdminRequest;
use App\Http\Requests\Maintenance\RestoreBackupRequest;
use App\Services\Backup\AuditLogIntegrity;
use App\Services\Backup\BackupService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

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
        // Read-only: the SMTP server's certificate chain and whether it verifies.
        'mail-probe' => [['ethr:mail-probe', []]],
    ];

    /**
     * One of the fixed tasks: `migrate`, `seed`, `optimize`, `clear` or the read-only `mail-probe`.
     */
    #[ExcludeRouteFromDocs]
    public function run(string $task): JsonResponse
    {
        abort_unless(array_key_exists($task, self::TASKS), 404);

        return $this->exclusively($task, function () use ($task): array {
            $steps = [];

            foreach (self::TASKS[$task] as [$command, $arguments]) {
                // A migration that fails throws rather than returning an exit
                // code. Uncaught, that was a bare "Server Error" whose cause
                // was only in the host's log (2026-10-10). The message goes to
                // the token holder; report() still logs the full trace.
                try {
                    $exit = Artisan::call($command, $arguments);
                    $output = Artisan::output();
                } catch (Throwable $e) {
                    report($e);
                    $exit = 1;
                    $output = $e::class.': '.$e->getMessage();
                }

                $steps[] = ['command' => $command, 'exit_code' => $exit, 'output' => $this->tail($output)];

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
     * The backups on the host, newest first: name, when, and what is in it.
     * Read-only — the list the `restore` task chooses from.
     */
    #[ExcludeRouteFromDocs]
    public function backups(BackupService $backups): JsonResponse
    {
        return response()->json(['task' => 'backups', 'status' => 'ok', 'backups' => $this->listBackups($backups)]);
    }

    /** `ethr:backup` now, outside the 01:00 schedule — before a risky change, say. */
    #[ExcludeRouteFromDocs]
    public function backup(BackupService $backups): JsonResponse
    {
        return $this->exclusively('backup', function () use ($backups): array {
            $this->allowLongRun();

            try {
                $result = $backups->create('manual');
            } catch (Throwable $e) {
                report($e);

                return [['command' => 'ethr:backup', 'exit_code' => 1, 'output' => $e::class.': '.$e->getMessage()]];
            }

            return [['command' => 'ethr:backup', 'exit_code' => 0, 'output' => 'Backup '.$result['name'].' created.']];
        });
    }

    /**
     * Restore a backup — the recovery path the host otherwise lacks: its
     * deployment action has no PHP, and importing a dump through phpMyAdmin
     * breaks the audit_log triggers (BACKUP-RESTORE.md, the DEFINER problem).
     *
     * Four steps, stopping at the first failure:
     *  1. a fresh backup of the current state (`pre-restore`), so a restore of
     *     the wrong backup can itself be undone;
     *  2. the restore (BackupService::restore, which refuses a dump whose
     *     checksum does not match its manifest);
     *  3. a check that audit_log is still append-only, written as a
     *     `backup.restored` row naming both backups;
     *  4. the response says what to check by hand.
     */
    #[ExcludeRouteFromDocs]
    public function restore(RestoreBackupRequest $request, BackupService $backups, AuditLogIntegrity $integrity): JsonResponse
    {
        $name = (string) $request->validated('name');

        if (! in_array($name, array_column($this->listBackups($backups), 'name'), true)) {
            return response()->json([
                'task' => 'restore',
                'status' => 'refused',
                'detail' => "No backup named {$name} on this host. List them with the `backups` task.",
            ], 404);
        }

        return $this->exclusively('restore', function () use ($name, $backups, $integrity): array {
            $this->allowLongRun();
            $steps = [];

            try {
                $safety = $backups->create('pre-restore');
                $steps[] = ['command' => 'ethr:backup --label=pre-restore', 'exit_code' => 0, 'output' => 'Safety backup '.$safety['name'].' created; restore it to undo this.'];
            } catch (Throwable $e) {
                report($e);

                return [['command' => 'ethr:backup --label=pre-restore', 'exit_code' => 1, 'output' => 'No safety backup, so nothing was restored: '.$e->getMessage()]];
            }

            try {
                $result = $backups->restore($backups->backupRoot().DIRECTORY_SEPARATOR.$name);
                $steps[] = ['command' => "ethr:restore {$name}", 'exit_code' => 0, 'output' => sprintf('Restored %d statements, %d files.', $result['statements'], $result['files'])];
            } catch (Throwable $e) {
                report($e);
                $steps[] = ['command' => "ethr:restore {$name}", 'exit_code' => 1, 'output' => $e::class.': '.$e->getMessage().' — restore '.$safety['name'].' to return to where you were.'];

                return $steps;
            }

            $failures = $integrity->verifyAppendOnly('backup.restored', ['backup' => $name, 'safety_backup' => $safety['name']]);
            $steps[] = [
                'command' => 'audit_log append-only check',
                'exit_code' => $failures === [] ? 0 : 1,
                'output' => $failures === []
                    ? 'audit_log accepts inserts and refuses updates. Now sign in, read a payroll run, edit an employee and download a document.'
                    : implode(' ', $failures),
            ];

            return $steps;
        });
    }

    /**
     * @return list<array{name: string, created_at: string|null, tables: int|null, rows: int|null, files: int|null}>
     */
    private function listBackups(BackupService $backups): array
    {
        $root = $backups->backupRoot();
        if (! File::isDirectory($root)) {
            return [];
        }

        $list = [];
        foreach (File::directories($root) as $dir) {
            $manifestFile = $dir.DIRECTORY_SEPARATOR.'manifest.json';
            if (! File::exists($manifestFile)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestFile), true);
            $manifest = is_array($manifest) ? $manifest : [];

            $list[] = [
                'name' => basename($dir),
                'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : null,
                'tables' => isset($manifest['database']['tables']) ? (int) $manifest['database']['tables'] : null,
                'rows' => isset($manifest['database']['rows']) ? (int) $manifest['database']['rows'] : null,
                'files' => isset($manifest['files']['count']) ? (int) $manifest['files']['count'] : null,
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $list;
    }

    /**
     * A backup or restore can outlast the default execution limit on a shared
     * host (G0-J is unmeasured); a request cut off half-way through a restore
     * is the worst outcome there is.
     */
    private function allowLongRun(): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
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
