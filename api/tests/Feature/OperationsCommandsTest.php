<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Observability\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The four operator commands with no direct test: ethr:restore,
 * ethr:queue:check, ethr:backup:rehearse and health:check. Each exists for a
 * moment when something has already gone wrong, which is exactly when an
 * untested exit code or a misleading line of output costs the most.
 * docs/operations/COMMANDS-AND-SCHEDULE.md is the contract they are read
 * against.
 */

function opsCommandsBackupRoot(): string
{
    return storage_path('framework/testing/ops-commands-backups');
}

/** A backup directory with a manifest, as BackupService::create() writes one. */
function opsCommandsFakeBackup(string $name, bool $withManifest = true): string
{
    $dir = opsCommandsBackupRoot().DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($dir);
    File::put($dir.DIRECTORY_SEPARATOR.'database.sql', '-- nothing');

    if ($withManifest) {
        File::put($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'created_at' => '2026-09-30T01:00:00+00:00',
            'database' => ['tables' => 97, 'rows' => 4321],
            'files' => ['count' => 12],
        ]));
    }

    return $dir;
}

/** Bind a BackupService double that must never restore. */
function opsCommandsNoRestore(): void
{
    test()->mock(BackupService::class, function ($mock) {
        $mock->shouldReceive('backupRoot')->andReturn(opsCommandsBackupRoot());
        $mock->shouldNotReceive('restore');
    });
}

beforeEach(function () {
    File::deleteDirectory(opsCommandsBackupRoot());
});

afterEach(function () {
    File::deleteDirectory(opsCommandsBackupRoot());
});

// Ã¢â€â‚¬Ã¢â€â‚¬ ethr:restore Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

describe('ethr:restore', function () {
    it('fails, and touches nothing, when the named backup does not exist', function () {
        opsCommandsNoRestore();

        $this->artisan('ethr:restore', ['name' => 'no-such-backup', '--force' => true])
            ->expectsOutputToContain('No backup at')
            ->assertExitCode(1);
    });

    it('shows what it is about to restore before asking', function () {
        opsCommandsFakeBackup('2026-09-30_010000');
        opsCommandsNoRestore();

        $this->artisan('ethr:restore', ['name' => '2026-09-30_010000'])
            ->expectsOutputToContain('created   2026-09-30T01:00:00+00:00')
            ->expectsOutputToContain('database  97 tables, 4321 rows')
            ->expectsOutputToContain('documents 12 files')
            ->expectsConfirmation(
                'This DROPS every table in the current database and overwrites storage/app/private. Continue?',
                'no'
            )
            ->expectsOutputToContain('Aborted.');
    });

    it('warns that an unmanifested dump cannot be checksum-verified', function () {
        opsCommandsFakeBackup('bare', withManifest: false);
        opsCommandsNoRestore();

        $this->artisan('ethr:restore', ['name' => 'bare'])
            ->expectsOutputToContain('No manifest.json')
            ->expectsConfirmation(
                'This DROPS every table in the current database and overwrites storage/app/private. Continue?',
                'no'
            );
    });

    it('never restores without the confirmation or --force', function () {
        // Non-interactive with no --force (a cron entry missing the flag) must
        // not be read as consent: confirm() answers its default, which is no.
        opsCommandsFakeBackup('cron-run');
        opsCommandsNoRestore();

        // Artisan::call rather than $this->artisan(): the test helper mocks the
        // console output and intercepts every prompt, so it cannot show what a
        // genuinely non-interactive run answers.
        Artisan::call('ethr:restore', ['name' => 'cron-run', '--no-interaction' => true]);

        expect(Artisan::output())->toContain('Aborted.');
    });

    it('reports a declined or non-interactive restore as a failure, not a success', function () {
        // RestoreCommand.php:57 returns self::SUCCESS after "Aborted.". A cron
        // line or script that forgot --force therefore exits 0 having restored
        // nothing Ã¢â‚¬â€ `ethr:restore X && echo restored` prints "restored".
        // Laravel's own destructive commands (migrate:fresh, db:wipe) return 1
        // when confirmation is declined.
        opsCommandsFakeBackup('cron-run');
        opsCommandsNoRestore();

        expect(Artisan::call('ethr:restore', ['name' => 'cron-run', '--no-interaction' => true]))->toBe(1);
    })->todo(note: 'DEFECT: RestoreCommand.php:57 exits 0 ("Aborted.") when nothing was restored, so a cron/script without --force reports success');

    it('restores with --force and tells the operator how to verify', function () {
        $dir = opsCommandsFakeBackup('nightly');

        $this->mock(BackupService::class, function ($mock) use ($dir) {
            $mock->shouldReceive('backupRoot')->andReturn(opsCommandsBackupRoot());
            $mock->shouldReceive('restore')->once()->with($dir)->andReturn(['statements' => 1234, 'files' => 7]);
        });

        $this->artisan('ethr:restore', ['name' => 'nightly', '--force' => true])
            ->expectsOutputToContain('Restored 1234 statements, 7 files.')
            ->expectsOutputToContain('php artisan ethr:backup:verify')
            ->assertExitCode(0);
    });

    it('accepts an absolute path as well as a directory name', function () {
        $dir = opsCommandsFakeBackup('copied-from-elsewhere');

        $this->mock(BackupService::class, function ($mock) use ($dir) {
            $mock->shouldReceive('backupRoot')->andReturn('/somewhere/else');
            $mock->shouldReceive('restore')->once()->with($dir)->andReturn(['statements' => 1, 'files' => 0]);
        });

        $this->artisan('ethr:restore', ['name' => $dir, '--force' => true])->assertExitCode(0);
    });

    it('fails loudly when the restore throws', function () {
        opsCommandsFakeBackup('corrupt');

        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('backupRoot')->andReturn(opsCommandsBackupRoot());
            $mock->shouldReceive('restore')->once()->andThrow(new RuntimeException('does not match the sha256'));
        });

        $this->artisan('ethr:restore', ['name' => 'corrupt', '--force' => true])
            ->expectsOutputToContain('Restore FAILED: does not match the sha256')
            ->assertExitCode(1);
    });

    it('restores after an interactive yes', function () {
        $dir = opsCommandsFakeBackup('interactive');

        $this->mock(BackupService::class, function ($mock) use ($dir) {
            $mock->shouldReceive('backupRoot')->andReturn(opsCommandsBackupRoot());
            $mock->shouldReceive('restore')->once()->with($dir)->andReturn(['statements' => 3, 'files' => 0]);
        });

        $this->artisan('ethr:restore', ['name' => 'interactive'])
            ->expectsConfirmation(
                'This DROPS every table in the current database and overwrites storage/app/private. Continue?',
                'yes'
            )
            ->assertExitCode(0);
    });
});

// Ã¢â€â‚¬Ã¢â€â‚¬ ethr:queue:check Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

function opsCommandsQueueJob(string $queue, int $ageSeconds): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - $ageSeconds,
        'created_at' => time() - $ageSeconds,
    ]);
}

describe('ethr:queue:check', function () {
    it('exits 0 and lists every queue the application uses when all is well', function () {
        app(QueueHealth::class)->beat();

        $command = $this->artisan('ethr:queue:check')->expectsOutputToContain('OK');

        foreach (QueueHealth::QUEUES as $queue) {
            $command->expectsOutputToContain($queue);
        }

        $command->assertExitCode(0);
    });

    it('names the external cron caller when the scheduler has never run', function () {
        $this->artisan('ethr:queue:check')
            ->expectsOutputToContain('NEVER RUN')
            ->expectsOutputToContain('is the external cron caller configured (CRON_TOKEN and base URL)?')
            ->assertExitCode(1);
    });

    it('flags a scheduler that has gone quiet, against the threshold it was given', function () {
        app(QueueHealth::class)->beat();
        $this->travel(20)->minutes();

        $this->artisan('ethr:queue:check')
            ->expectsOutputToContain('(threshold 900s)')
            ->assertExitCode(1);

        $this->artisan('ethr:queue:check', ['--scheduler-stale' => 3600])->assertExitCode(0);
    });

    it('flags one starving queue even when the others are empty', function () {
        // Per-queue, not total: a worker draining only `default` leaves the
        // others starving while the total looks healthy.
        app(QueueHealth::class)->beat();
        opsCommandsQueueJob('notifications', 3600);

        $this->artisan('ethr:queue:check')
            ->expectsOutputToContain('queue "notifications" has a job waiting')
            ->assertExitCode(1);

        $this->artisan('ethr:queue:check', ['--oldest-job' => 7200])->assertExitCode(0);
    });

    it('reports failed jobs without failing on them', function () {
        app(QueueHealth::class)->beat();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->artisan('ethr:queue:check')
            ->expectsOutputToContain('failed jobs 1')
            ->assertExitCode(0);
    });

    it('emits machine-readable JSON whose exit code tracks its status', function () {
        $this->artisan('ethr:queue:check', ['--json' => true])->assertExitCode(1);

        app(QueueHealth::class)->beat();

        $this->artisan('ethr:queue:check', ['--json' => true])
            ->expectsOutputToContain('"status": "healthy"')
            ->assertExitCode(0);
    });

    it('does not send the operator to a Plesk Scheduled Tasks section that does not exist', function () {
        // QueueHealth's message was corrected on 2026-09-28 because G0-D found
        // no Scheduled Tasks section on the target account
        // (docs/operations/QUEUE-MONITORING.md, "fixed 2026-09-28"). The
        // command's own closing remedy, QueueCheckCommand.php:85-86, was missed
        // and still prints "check Plesk -> Scheduled Tasks for: * * * * * cd
        // ~/ethr/api && php artisan schedule:run" Ã¢â‚¬â€ a panel section and a cron
        // line that do not exist on this host, printed right under the
        // corrected message.
        $this->artisan('ethr:queue:check')
            ->doesntExpectOutputToContain('Scheduled Tasks')
            ->assertExitCode(1);
    })->todo(note: 'DEFECT: QueueCheckCommand.php:85-86 still tells operators to check Plesk -> Scheduled Tasks, which G0-D found does not exist; the caller is cron.yml');
});

// Ã¢â€â‚¬Ã¢â€â‚¬ ethr:backup:rehearse Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

describe('ethr:backup:rehearse', function () {
    it('refuses a database whose name does not look disposable, and drops nothing', function () {
        createTenant();
        $tablesBefore = count(DB::select("SELECT name FROM sqlite_master WHERE type='table'"));

        // The suite's database is ':memory:' Ã¢â‚¬â€ no 'test', 'scratch', ... in it.
        $this->artisan('ethr:backup:rehearse')
            ->expectsOutputToContain('does not look disposable')
            ->expectsOutputToContain('re-run with --force')
            ->assertExitCode(1);

        expect(count(DB::select("SELECT name FROM sqlite_master WHERE type='table'")))->toBe($tablesBefore)
            ->and(DB::table('tenants')->count())->toBe(1);
    })->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'Reads sqlite_master; the guard itself is driver-neutral.');

    it('refuses to run in the production environment before anything else', function () {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('ethr:backup:rehearse')
                ->expectsOutputToContain('Refusing to run in the production environment.')
                ->assertExitCode(1);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        expect(DB::table('migrations')->count())->toBeGreaterThan(0);
    });

    it('destroys and rebuilds the database with --force, and proves audit_log is still append-only', function () {
        // The full rehearsal, on the one driver where the drop is undone by
        // RefreshDatabase's transaction Ã¢â‚¬â€ see BackupRestoreRehearsalTest for
        // why this must not run on MySQL.
        config(['backup.path' => opsCommandsBackupRoot()]);
        $tenant = createTenant(['subdomain' => 'rehearsed']);

        $this->artisan('ethr:backup:rehearse', ['--force' => true])
            ->expectsOutputToContain('tables remaining')
            ->expectsOutputToContain('audit_log UPDATE rejected')
            ->expectsOutputToContain('Rehearsal passed.')
            ->assertExitCode(0);

        expect(DB::table('tenants')->where('subdomain', 'rehearsed')->value('public_id'))->toBe($tenant->public_id)
            ->and(File::directories(opsCommandsBackupRoot()))->toBe([]);
    })->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'Drops every table; only SQLite rolls that back.');
});

// Ã¢â€â‚¬Ã¢â€â‚¬ health:check Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

describe('health:check', function () {
    it('exits 0 when the database and the configured cache store answer', function () {
        $this->artisan('health:check')->assertExitCode(0);

        expect(cache()->get('health_check'))->toBeTrue();
    });

    it('checks the configured cache store, not redis by name', function () {
        // As a hardcoded `redis` store this reported every Redis-less
        // deployment Ã¢â‚¬â€ which is every shared-hosting one Ã¢â‚¬â€ as unhealthy.
        config([
            'cache.default' => 'array',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
        ]);

        $this->artisan('health:check')->assertExitCode(0);
    });

    it('exits 1 and says why when the cache store cannot be used', function () {
        config([
            'cache.default' => 'ops_broken',
            'cache.stores.ops_broken' => ['driver' => 'no-such-driver'],
        ]);

        $this->artisan('health:check')
            ->expectsOutputToContain('no-such-driver')
            ->assertExitCode(1);
    });

    it('exits 1 when the database cannot be reached', function () {
        $default = config('database.default');
        config([
            'database.connections.ops_broken' => [
                'driver' => 'sqlite',
                'database' => storage_path('framework/testing/no-such-dir/none.sqlite'),
            ],
            'database.default' => 'ops_broken',
        ]);

        try {
            $this->artisan('health:check')->assertExitCode(1);
        } finally {
            config(['database.default' => $default]);
            DB::purge('ops_broken');
        }
    });
});
