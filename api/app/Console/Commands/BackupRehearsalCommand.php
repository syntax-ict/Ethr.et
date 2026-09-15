<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Destroy the database and restore it from a backup, then check what came back.
 *
 *   php artisan ethr:backup:rehearse
 *
 * Master plan §30 sets the standard this exists to meet: *a backup that has
 * never been restored is not considered verified*. Every other check in this
 * repository inspects a dump; this one throws the database away and rebuilds it
 * from the file, which is the only test that answers the question actually
 * being asked.
 *
 * ## Why a command and not only a test
 *
 * `tests/Feature/BackupRestoreRehearsalTest.php` proves the round-trip on
 * SQLite, in memory, with the schema the test suite builds. Production is
 * MySQL, on a host nobody has run this code on, with a schema built by
 * `migrate` rather than by a test. The two differ in ways that have already
 * bitten this project once — see the driver note in `DatabaseDumper::quoteFlat()`
 * — so the rehearsal has to be runnable where the risk actually lives.
 *
 * On Ethio Telecom's shared hosting the operator runs this against a scratch
 * database before the first real employee record exists. That is item 3 of the
 * checklist in `docs/deployment/BACKUP-RESTORE.md`, and until it has been run
 * there, ETHR has a backup procedure rather than a verified backup.
 *
 * ## It drops every table
 *
 * That is not a side effect, it is the method. Two independent guards therefore
 * stand in front of it, and `--force` is required to pass either:
 *
 *   - the environment must not be `production`
 *   - the database name must look disposable (`test`, `rehearsal`, `scratch`,
 *     `staging`)
 *
 * A database that fails the name check is far more likely to be someone's real
 * data than a deliberately odd choice of scratch name, so the default is to
 * refuse and say what it wanted.
 */
class BackupRehearsalCommand extends Command
{
    protected $signature = 'ethr:backup:rehearse
        {--force : Proceed even though the environment or database name failed a safety check}
        {--keep : Leave the backup directory behind for inspection}';

    protected $description = 'Destructively verify the backup path: back up, drop everything, restore, check';

    /** Names that read as disposable. Anything else needs --force. */
    private const DISPOSABLE = ['test', 'rehears', 'scratch', 'staging', 'sandbox'];

    public function handle(BackupService $backups): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();

        if (! $this->guardsPass($database)) {
            return self::FAILURE;
        }

        $this->components->info("Rehearsing backup and restore on [{$driver}] {$database}");
        $this->line('  server version : '.$this->serverVersion());
        $this->newLine();

        $before = $this->census();
        $this->reportCensus('before', $before);

        if ($before['tables'] === 0) {
            $this->components->error('No tables. Run `php artisan migrate` first — restoring an empty database proves nothing.');

            return self::FAILURE;
        }

        // ── Back up ──────────────────────────────────────────────────────────
        $backup = $backups->create('rehearsal');
        $sqlPath = $backup['path'].DIRECTORY_SEPARATOR.'database.sql';
        $this->components->twoColumnDetail('backup written', $backup['path']);
        $this->components->twoColumnDetail('dump size', number_format((int) File::size($sqlPath)).' bytes');

        $failures = $this->inspectDump($sqlPath, $driver, $before['triggers']);

        // ── Destroy ──────────────────────────────────────────────────────────
        $this->newLine();
        $this->components->warn('Dropping every table.');
        $this->dropEverything();

        $emptied = $this->census();
        if ($emptied['tables'] !== 0) {
            $this->components->error("{$emptied['tables']} tables survived the drop — the restore below would prove nothing.");

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('tables remaining', '0');

        // ── Restore ──────────────────────────────────────────────────────────
        try {
            $result = $backups->restore($backup['path']);
            $this->components->twoColumnDetail('statements executed', (string) $result['statements']);
            $this->components->twoColumnDetail('files restored', (string) $result['files']);
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('RESTORE FAILED: '.$e->getMessage());
            $this->line('  The backup exists and cannot be restored, which is the worst of the three');
            $this->line('  possible outcomes: it looks like protection and is not.');

            return self::FAILURE;
        }

        // ── Verify ───────────────────────────────────────────────────────────
        $after = $this->census();
        $this->newLine();
        $this->reportCensus('after', $after);

        $failures = [...$failures, ...$this->compare($before, $after)];
        $failures = [...$failures, ...$this->checkAuditLogStillAppendOnly()];

        if (! $this->option('keep')) {
            File::deleteDirectory($backup['path']);
            @unlink($backup['path'].'.tar.gz');
        }

        $this->newLine();

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->components->error($failure);
            }

            return self::FAILURE;
        }

        $this->components->info('Rehearsal passed. This database was destroyed and rebuilt from its backup.');
        $this->line('  Record the result in docs/deployment/BACKUP-RESTORE.md with the date and this');
        $this->line('  server version. A rehearsal on a different host does not carry over.');

        return self::SUCCESS;
    }

    /** Both safety gates. Returns false when the run must not proceed. */
    private function guardsPass(string $database): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (app()->environment('production')) {
            $this->components->error('Refusing to run in the production environment.');
            $this->line('  This command drops every table. Point it at a scratch database instead.');

            return false;
        }

        $haystack = mb_strtolower($database);

        foreach (self::DISPOSABLE as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        $this->components->error("Database [{$database}] does not look disposable.");
        $this->line('  This command DROPS EVERY TABLE. It expects a name containing one of: '
            .implode(', ', self::DISPOSABLE).'.');
        $this->line('  If that really is a scratch database, re-run with --force.');

        return false;
    }

    private function serverVersion(): string
    {
        try {
            return (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Count what is here, so "it came back" can be checked rather than assumed.
     *
     * @return array{tables: int, rows: int, triggers: int}
     */
    private function census(): array
    {
        $tables = $this->tableNames();
        $rows = 0;

        foreach ($tables as $table) {
            try {
                $rows += (int) DB::table($table)->count();
            } catch (Throwable) {
                // A table that cannot be counted is itself a finding, but the
                // comparison below catches it via the table count.
            }
        }

        return ['tables' => count($tables), 'rows' => $rows, 'triggers' => count($this->triggerNames())];
    }

    /** @return array<int, string> */
    private function tableNames(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            ))->pluck('name')->all();
        }

        return array_map(
            fn ($row) => array_values((array) $row)[0],
            $connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')
        );
    }

    /** @return array<int, string> */
    private function triggerNames(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("SELECT name FROM sqlite_master WHERE type='trigger'"))
                ->pluck('name')->all();
        }

        return array_map(
            fn ($row) => ((array) $row)['Trigger'],
            $connection->select('SHOW TRIGGERS')
        );
    }

    /**
     * Read the dump before restoring it. Two properties matter and neither is
     * visible after a successful restore on the machine that wrote it.
     *
     * @return array<int, string>
     */
    private function inspectDump(string $sqlPath, string $driver, int $expectedTriggers): array
    {
        $failures = [];
        $sql = (string) File::get($sqlPath);

        $createTriggers = substr_count($sql, 'CREATE TRIGGER');

        if ($expectedTriggers > 0 && $createTriggers < $expectedTriggers) {
            $failures[] = "Dump has {$createTriggers} CREATE TRIGGER statements for {$expectedTriggers} triggers. "
                .'A restore that loses these leaves audit_log mutable and nothing reports it.';
        }

        $this->components->twoColumnDetail('CREATE TRIGGER in dump', (string) $createTriggers);

        // The DEFINER hazard. MySQL records a definer with every trigger and
        // SHOW CREATE TRIGGER emits it; restore under a different user and every
        // INSERT into audit_log fails, which on this application means every
        // mutating endpoint returns 500. The dumper reconstructs triggers from
        // SHOW TRIGGERS precisely so no DEFINER is carried.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Match the CLAUSE, not the word. The dump's own header says
            // "Triggers carry no DEFINER on purpose", so a substring search for
            // DEFINER goes red on every successful run — which is how a check
            // stops being read. mysqldump emits `CREATE DEFINER=x TRIGGER y`, so
            // the clause is what to look for, anchored to a CREATE so a data row
            // quoting the word cannot trip it either.
            $definerLines = preg_grep('/^\s*CREATE\b.*\bDEFINER\s*=/i', explode("\n", $sql)) ?: [];
            $hasDefiner = $definerLines !== [];

            $this->components->twoColumnDetail(
                'DEFINER clauses',
                $hasDefiner ? '<fg=red>'.count($definerLines).' present</>' : '<fg=green>none</>'
            );

            if ($hasDefiner) {
                $failures[] = 'The dump carries a DEFINER clause. Restoring it as a different database '
                    .'user — which Plesk backup tooling and phpMyAdmin routinely do — makes every '
                    .'audit_log INSERT fail. See docs/audit/BASELINE.md §13b.';
            }
        }

        return $failures;
    }

    /**
     * Drop every table, in repeated passes.
     *
     * Two strategies, because one does not cover both drivers.
     *
     * On MySQL the repeated-pass approach is not merely slow, it cannot finish:
     * ETHR's schema has genuine foreign-key cycles — `tenants` ↔ `users`,
     * `employees` ↔ `departments` ↔ `branches` — and MySQL refuses to drop a
     * table another table still references, whatever the order. Measured on
     * MariaDB 10.4.32: ten tables survived every pass. `SET FOREIGN_KEY_CHECKS=0`
     * is honoured here (this is not inside a transaction) and is the standard
     * answer.
     *
     * On SQLite the equivalent pragma is silently ignored inside a transaction,
     * and the test suite's `RefreshDatabase` puts every test in one — so there
     * the repeated passes are what works. Dependency-order-agnostic: drop
     * whatever will drop, go round again, stop when a pass makes no progress.
     */
    private function dropEverything(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($this->tableNames() as $table) {
                    DB::statement('DROP TABLE IF EXISTS '.$this->wrap($table));
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        $remaining = $this->tableNames();

        while ($remaining !== []) {
            $before = count($remaining);
            $failed = [];

            foreach ($remaining as $table) {
                try {
                    DB::statement('DROP TABLE IF EXISTS '.$this->wrap($table));
                } catch (Throwable) {
                    $failed[] = $table;
                }
            }

            $remaining = $failed;

            if (count($remaining) === $before) {
                $this->components->warn('Could not drop: '.implode(', ', $remaining));
                break;
            }
        }
    }

    private function wrap(string $identifier): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? '"'.$identifier.'"'
            : '`'.$identifier.'`';
    }

    /**
     * @param  array{tables: int, rows: int, triggers: int}  $before
     * @param  array{tables: int, rows: int, triggers: int}  $after
     * @return array<int, string>
     */
    private function compare(array $before, array $after): array
    {
        $failures = [];

        foreach (['tables', 'rows', 'triggers'] as $key) {
            if ($after[$key] !== $before[$key]) {
                $failures[] = sprintf(
                    '%s: %d before, %d after — the restore is not faithful.',
                    $key, $before[$key], $after[$key]
                );
            }
        }

        return $failures;
    }

    /**
     * The check the whole DEFINER argument exists for.
     *
     * A restore can bring the triggers back by name and still leave them
     * broken, so this exercises them: an INSERT must succeed and an UPDATE must
     * be rejected. Convention #5 says audit_log is append-only; after a restore
     * is exactly when that quietly stops being true.
     *
     * @return array<int, string>
     */
    private function checkAuditLogStillAppendOnly(): array
    {
        $failures = [];

        if (! in_array('audit_log', $this->tableNames(), true)) {
            return ['audit_log did not come back at all.'];
        }

        try {
            $id = DB::table('audit_log')->insertGetId([
                'action' => 'backup.rehearsal',
                'payload' => json_encode(['note' => 'written after restore']),
                'created_at' => now(),
            ]);
            $this->components->twoColumnDetail('audit_log INSERT after restore', '<fg=green>ok</>');
        } catch (Throwable $e) {
            // This is the DEFINER failure's actual signature. AuditLog::record()
            // runs inside the write transaction of nearly every mutating
            // endpoint, so in production this reads as "everything returns 500".
            return ['audit_log INSERT failed after restore: '.$e->getMessage()
                .' — if this mentions a definer, the dump carried one.'];
        }

        try {
            DB::table('audit_log')->where('id', $id)->update(['action' => 'tampered']);
            $failures[] = 'audit_log accepted an UPDATE after restore — the append-only triggers '
                .'came back by name but are not enforcing. The log is no longer evidence.';
            $this->components->twoColumnDetail('audit_log UPDATE rejected', '<fg=red>NO</>');
        } catch (Throwable) {
            $this->components->twoColumnDetail('audit_log UPDATE rejected', '<fg=green>yes</>');
        }

        return $failures;
    }

    /** @param  array{tables: int, rows: int, triggers: int}  $census */
    private function reportCensus(string $label, array $census): void
    {
        $this->components->twoColumnDetail(
            "{$label}: tables / rows / triggers",
            "{$census['tables']} / {$census['rows']} / {$census['triggers']}"
        );
    }
}
