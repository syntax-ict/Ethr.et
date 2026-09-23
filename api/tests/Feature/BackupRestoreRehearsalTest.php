<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * An actual destructive restore rehearsal, not a dump-and-inspect.
 *
 * Master plan §31 asks for exactly this sequence: populate representative data,
 * back up, *destroy the database*, restore, and verify schema, data, tenant
 * isolation, audit logging and files came back. §30 states the standard these
 * tests exist to meet — "a backup that has never been restored is not
 * considered verified".
 *
 * Before this, ETHR had no backup path that worked anywhere but Docker
 * (scripts/backup.sh is `docker compose exec mariadb mysqldump`), and no
 * restore path at all. docs/audit/BASELINE.md ranked that the largest single
 * risk in the system — above the cross-tenant defect — because a product
 * holding salary history and scanned identity documents with no recoverable
 * failure mode has no floor under it.
 *
 * ## What this proves, and what it does not
 *
 * Proves: the dumper and restorer round-trip real ETHR data on the connection
 * the test suite runs against, that a corrupted dump is refused rather than
 * half-applied, and that triggers survive.
 *
 * Does not prove: that a restore works on Ethio Telecom's MySQL. That needs the
 * host, and `docs/deployment/GATE-0-RESULT.md` records every hosting capability
 * as NOT VERIFIED. A green run here is a necessary condition, not the rehearsal
 * that closes the go-live gate.
 */
beforeEach(function () {
    // SQLite only, and not merely because the helpers below read `sqlite_master`.
    //
    // These tests DROP EVERY TABLE, which is the whole point — a restore into a
    // surviving schema proves nothing. On SQLite that is undone by the
    // transaction `RefreshDatabase` rolls back. On MySQL, DDL implicitly
    // commits, so the drop is permanent: the database stays destroyed and every
    // test that runs afterwards fails with a missing table. Measured on
    // MariaDB 10.4.32 — these three failures took `WriteEndpointSmokeTest` down
    // with them, which is how the cause was found.
    //
    // The MySQL equivalent is `php artisan ethr:backup:rehearse`, which does the
    // same sequence against a scratch database where destroying it is safe. See
    // docs/deployment/BACKUP-RESTORE.md.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped(
            'Destructive restore rehearsal is SQLite-only; use `artisan ethr:backup:rehearse` on MySQL.'
        );
    }

    config(['backup.path' => storage_path('framework/testing/backups')]);
    File::deleteDirectory(config('backup.path'));
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
});

/**
 * Drop every table, so a restore has to rebuild the schema rather than replay
 * INSERTs into tables that happened to survive.
 *
 * `PRAGMA foreign_keys=OFF` is deliberately NOT used: SQLite ignores that pragma
 * inside a transaction, and RefreshDatabase wraps every test in one, so it
 * silently does nothing. SQLite then validates FK targets on DROP and fails with
 * "no such table: main.plans" as soon as a referenced parent has already gone.
 *
 * Repeated passes instead — drop whatever will drop, until a pass makes no
 * progress. Dependency-order-agnostic, needs no pragma, and works on any driver.
 */
function destroyDatabase(): void
{
    $remaining = tableNames();

    while ($remaining !== []) {
        $before = count($remaining);
        $failed = [];

        foreach ($remaining as $table) {
            try {
                DB::statement("DROP TABLE IF EXISTS \"{$table}\"");
            } catch (Throwable) {
                $failed[] = $table;
            }
        }

        $remaining = $failed;

        if (count($remaining) === $before) {
            // A genuine cycle, or something the driver will not drop. Say so
            // rather than spinning: a test that hangs teaches nothing.
            throw new RuntimeException(
                'Could not drop: '.implode(', ', $remaining).' — circular foreign keys?'
            );
        }
    }
}

/** @return array<int, string> */
function tableNames(): array
{
    return collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name')
        ->all();
}

/**
 * Every table carrying a generated column, read from the live schema.
 *
 * Schema-driven on purpose. A hardcoded list is the thing that failed: the
 * round-trip fixtures held no `devices` row when `serial_number_active` landed,
 * so the test that exists to catch exactly this never saw it.
 *
 * @return array<string, array<int, string>> table => generated columns
 */
function tablesWithGeneratedColumns(): array
{
    $found = [];

    foreach (tableNames() as $table) {
        $generated = collect(DB::select("PRAGMA table_xinfo('".str_replace("'", "''", $table)."')"))
            ->filter(fn ($column) => (int) $column->hidden !== 0)
            ->pluck('name')
            ->all();

        if ($generated !== []) {
            $found[$table] = $generated;
        }
    }

    return $found;
}

/**
 * One row in every table that has a generated column.
 *
 * Kept as one helper rather than spread across the tests so the coverage
 * assertion below has a single thing to check, and so adding a table here is
 * the obvious fix when it fails.
 *
 * @return array{tenant: \App\Models\Tenant, employee: Employee, user: User, device: Device}
 */
function generatedColumnFixture(): array
{
    $tenant = createTenant(['subdomain' => 'habru']);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Almaz Tesfaye',
        'employee_code' => 'EMP-RESTORE-1',
        'phone' => '0911 22 33 44',
        'salary_cents' => 1234500,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'email' => 'Selam@Acme.test',
        'phone' => '0911 55 66 77',
    ]);

    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'serial_number' => 'SN-RESTORE-1',
    ]);

    return ['tenant' => $tenant, 'employee' => $employee, 'user' => $user, 'device' => $device];
}

it('restores the database after it has been destroyed', function () {
    // The fixture covers every table with a generated column, not only
    // `employees`. Before 2026-09-23 this test created a tenant and an employee
    // and nothing else, so `devices` had no row and the dump never exercised
    // `serial_number_active` — which is how §15f shipped under a green suite.
    ['tenant' => $tenant, 'employee' => $employee] = generatedColumnFixture();

    $employeesBefore = Employee::withoutGlobalScopes()->count();
    $tenantsBefore = Tenant::withoutGlobalScopes()->count();

    $backup = app(BackupService::class)->create('rehearsal');

    // Destroy it. Not truncate — drop, so a restore that only replays INSERTs
    // against a surviving schema would fail here rather than pass by accident.
    destroyDatabase();

    expect(tableNames())
        ->toBeEmpty('The database was not actually destroyed, so the restore below proves nothing.');

    app(BackupService::class)->restore($backup['path']);

    // Schema and data are back.
    expect(Tenant::withoutGlobalScopes()->count())->toBe($tenantsBefore)
        ->and(Employee::withoutGlobalScopes()->count())->toBe($employeesBefore);

    $restored = Employee::withoutGlobalScopes()->where('employee_code', 'EMP-RESTORE-1')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->name)->toBe('Almaz Tesfaye')
        ->and($restored->tenant_id)->toBe($tenant->id)
        // Integer minor units, per convention 3. A float round-trip through the
        // dump would show up here as 1234499 or 1234501.
        ->and($restored->salary_cents)->toBe(1234500)
        ->and($restored->public_id)->toBe($employee->public_id);
});

it('has a fixture row in every table that carries a generated column', function () {
    // The assertion that would have caught §15f, and the reason it is written
    // against the schema rather than a list: a generated column added to a
    // table these fixtures do not touch makes this fail, and the failure names
    // the table so the fix is obvious.
    generatedColumnFixture();

    $tables = tablesWithGeneratedColumns();

    expect($tables !== [])->toBeTrue('No generated columns found at all — the schema query is wrong, not the fixture.');

    foreach ($tables as $table => $columns) {
        expect(DB::table($table)->count() > 0)->toBeTrue(
            "`{$table}` carries generated column(s) ".implode(', ', $columns)
            .' and the backup fixture creates no row in it, so the round-trip tests below cannot '
            .'exercise them. Add one to generatedColumnFixture().'
        );
    }
});

it('never replays a generated column, so a dump stays restorable', function () {
    // `SELECT *` returns generated columns like any other, and an INSERT naming
    // one is rejected on replay — outright on SQLite, and on MariaDB depending
    // on `sql_mode`, which belongs to whoever runs the restore rather than to
    // the backup. A dump that replays where it was written and not where it is
    // needed is the same defect wearing a hat.
    generatedColumnFixture();

    $backup = app(BackupService::class)->create('generated-columns');
    $sql = File::get($backup['path'].DIRECTORY_SEPARATOR.'database.sql');

    foreach (tablesWithGeneratedColumns() as $table => $columns) {
        foreach ($columns as $column) {
            // The definition must reach the restored schema...
            expect(str_contains($sql, $column))
                ->toBeTrue("The dump does not define `{$table}`.`{$column}` at all.");
        }
    }

    // ...and no INSERT may supply a value for one. Asked of the dump as a whole
    // rather than of one column, so this covers whatever the schema grows next.
    expect(app(DatabaseDumper::class)->findGeneratedColumnInserts(
        $backup['path'].DIRECTORY_SEPARATOR.'database.sql'
    ))->toBe([]);

    destroyDatabase();
    app(BackupService::class)->restore($backup['path']);

    // And the restored database recomputes them, so the login-identifier
    // indexes are populated on the other side of a restore rather than empty.
    $restored = DB::table('users')->where('email', 'Selam@Acme.test')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->email_normalized)->toBe('selam@acme.test')
        ->and($restored->phone_normalized)->toBe('0911556677');

    expect(DB::table('devices')->where('serial_number', 'SN-RESTORE-1')->value('serial_number_active'))
        ->toBe('SN-RESTORE-1');
});

it('refuses to write a backup whose INSERTs name a generated column', function () {
    // The check the manifest cannot do. sha256 proves the file is the one that
    // was written; it says nothing about whether the file can be put back, and
    // that gap is precisely what shipped in §15f — written without complaint,
    // hashing correctly, rejected only at restore time.
    //
    // Proven the only way a guard can be: against a dump produced the way the
    // pre-fix dumper produced them. `PreFixDumper` below is `writeRows()` as it
    // stood before 2026-09-23 — `SELECT *`, then an INSERT naming every column
    // it got back — so this fails against the old code and passes against the
    // new one, rather than passing against both.
    generatedColumnFixture();

    app()->bind(DatabaseDumper::class, fn () => new PreFixDumper);

    $root = app(BackupService::class)->backupRoot();
    $before = File::isDirectory($root) ? count(File::directories($root)) : 0;

    expect(fn () => app(BackupService::class)->create('pre-fix'))
        ->toThrow(RuntimeException::class, 'cannot be restored');

    // And it left nothing behind that could be mistaken for a backup: the
    // directory carries no manifest, so `prune()` would never list it.
    $after = File::isDirectory($root) ? count(File::directories($root)) : 0;

    expect($after)->toBe($before);
});

it('brings the audit_log triggers back, so the log is still append-only', function () {
    createTenant();

    $triggersBefore = collect(DB::select("SELECT name FROM sqlite_master WHERE type='trigger'"))->pluck('name');

    expect($triggersBefore)->not->toBeEmpty(
        'No triggers exist to begin with — this test would pass vacuously.'
    );

    $backup = app(BackupService::class)->create();

    destroyDatabase();

    app(BackupService::class)->restore($backup['path']);

    $triggersAfter = collect(DB::select("SELECT name FROM sqlite_master WHERE type='trigger'"))->pluck('name');

    // This is the assertion that matters most. A restore that silently loses
    // these leaves audit_log mutable, and nothing in the application would
    // report it — the endpoints keep working, the log just stops being
    // evidence. On MySQL the same restore also has to dodge the DEFINER
    // problem, which the dumper handles by emitting no DEFINER clause.
    expect($triggersAfter->sort()->values()->all())
        ->toBe($triggersBefore->sort()->values()->all());
});

it('restores employee documents alongside the database', function () {
    createTenant();

    $relative = 'tenants/01JTESTDOCUMENT/documents/contract.pdf';
    $full = storage_path('app/private/'.$relative);
    File::ensureDirectoryExists(dirname($full));
    File::put($full, 'a scanned contract');

    $backup = app(BackupService::class)->create();

    File::delete($full);
    expect(File::exists($full))->toBeFalse();

    app(BackupService::class)->restore($backup['path']);

    // Employee documents are half the recovery. A database-only backup restores
    // the row that says a contract exists and not the contract.
    expect(File::exists($full))->toBeTrue()
        ->and(File::get($full))->toBe('a scanned contract');

    File::delete($full);
});

it('refuses a dump that does not match its manifest', function () {
    createTenant();
    $backup = app(BackupService::class)->create();

    File::append($backup['path'].DIRECTORY_SEPARATOR.'database.sql', "\n-- tampered\n");

    // Half-applying a corrupted dump is worse than refusing it: it restores
    // *something*, and nobody can say what. The checksum is in the manifest so
    // truncation by a failed transfer is caught too, not only editing.
    expect(fn () => app(BackupService::class)->restore($backup['path']))
        ->toThrow(RuntimeException::class, 'does not match the sha256');
});

it('round-trips text containing a semicolon followed by a newline', function () {
    // The dump's one-statement-per-line layout is the contract
    // BackupService::executeSqlFile() uses to find where a statement ends: it
    // buffers lines until one ends in a semicolon. A stored value containing a
    // raw newline can therefore end a statement early — and the exact trigger
    // is narrower than it first looks. `executeSqlFile` rtrims only "\r\n", so:
    //
    //   "clause 4; \nmore"   line ends "; "  -> trailing space, no flush, SAFE
    //   "clause 4;\nmore"    line ends ";"   -> flushes early, TEARS
    //   "clause 4;\r\nmore"  rtrim strips \r -> flushes early, TEARS
    //
    // Measured 2026-09-15: with the semicolon immediately before the newline,
    // restoring throws `unrecognized token: "'Signed clause 4;"` partway
    // through — after some tables have already been dropped and recreated. So
    // the failure is loud, but the backup is unrestorable and the attempt
    // leaves a half-built database.
    //
    // PDO does not close this uniformly, and the asymmetry runs the wrong way:
    //
    //   mysql   quote("a;\nb")  ->  'a;\nb'      newline escaped
    //   sqlite  quote("a;\nb")  ->  'a; <LF> b'  newline literal
    //
    // Production is MySQL and was safe. Every test in this file is SQLite and
    // was not — so the suite proving backups work was proving it on the weaker
    // driver, and no fixture happened to contain the byte pair that shows it.
    $tenant = createTenant();

    // Every newline here is immediately preceded by a semicolon, so each one is
    // a real trigger rather than a decorative one. Both line endings, and a
    // trailing newline, because they take different branches.
    $nasty = "Signed clause 4;\nthen clause 5;\r\nWindows line too;\n";

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-TEAR-1',
        'name_am' => $nasty,
    ]);

    // A second carrier, because the JSON payload of an audit row is where
    // multi-line free text actually reaches the database in this system.
    AuditLog::record('backup.tear', null, ['note' => $nasty]);

    $backup = app(BackupService::class)->create('tear');

    // Prove the dump itself is well formed before restoring it: no line of the
    // INSERT section may contain a raw newline inside a literal, which is the
    // same as saying every statement line ends in a semicolon.
    $sql = File::get($backup['path'].DIRECTORY_SEPARATOR.'database.sql');
    expect($sql)->toContain('char(10)');

    destroyDatabase();
    app(BackupService::class)->restore($backup['path']);

    $restored = Employee::withoutGlobalScopes()->where('employee_code', 'EMP-TEAR-1')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->name_am)->toBe($nasty)
        ->and($restored->public_id)->toBe($employee->public_id);

    $log = AuditLog::withoutGlobalScopes()->where('action', 'backup.tear')->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['note'])->toBe($nasty);
});

it('writes a manifest that describes what was captured', function () {
    $tenant = createTenant();
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);
    AuditLog::record('backup.test', null, ['note' => 'manifest coverage']);

    $backup = app(BackupService::class)->create('labelled');

    expect($backup['name'])->toContain('labelled')
        ->and($backup['manifest']['database']['tables'])->toBeGreaterThan(0)
        ->and($backup['manifest']['database']['rows'])->toBeGreaterThan(0)
        ->and($backup['manifest']['sql_sha256'])->toHaveLength(64)
        ->and($backup['manifest']['created_at'])->not->toBeEmpty();
});

it('keeps only the requested number of backups', function () {
    createTenant();
    $service = app(BackupService::class);

    foreach (['a', 'b', 'c'] as $label) {
        $service->create($label);
        // Directory names carry a per-second timestamp, so without this the
        // three collide and prune has nothing to choose between.
        sleep(1);
    }

    expect(File::directories($service->backupRoot()))->toHaveCount(3);

    $removed = $service->prune(2);

    expect($removed)->toHaveCount(1)
        ->and(File::directories($service->backupRoot()))->toHaveCount(2);
});

/**
 * `DatabaseDumper` as it stood before 2026-09-23 — data written from
 * `SELECT *`, so every INSERT names the generated columns too.
 *
 * Copied from the commit that replaced it rather than hand-written, so the test
 * above measures the real regression instead of a plausible-looking imitation
 * of it. It overrides only the data pass; schema and triggers come from the
 * parent unchanged, because those halves were never the defect.
 */
final class PreFixDumper extends DatabaseDumper
{
    public function dumpTo(string $path): array
    {
        $stats = parent::dumpTo($path);

        $tables = array_keys(tablesWithGeneratedColumns());

        // Drop the fixed dumper's INSERTs for those tables, then write them the
        // old way. Replacing rather than appending matters: a dump carrying
        // both shapes is not a dump the pre-fix code could ever have produced,
        // and a check that only looked at the first row per table would score
        // it clean.
        $kept = array_filter(
            explode("\n", (string) File::get($path)),
            function (string $line) use ($tables): bool {
                foreach ($tables as $table) {
                    if (str_starts_with($line, 'INSERT INTO "'.$table.'" (')) {
                        return false;
                    }
                }

                return true;
            },
        );

        $lines = [];

        foreach ($tables as $table) {
            foreach (DB::table($table)->get() as $row) {
                $values = array_map(
                    fn ($value) => $value === null ? 'NULL' : "'".str_replace("'", "''", (string) $value)."'",
                    array_values((array) $row),
                );

                $lines[] = sprintf(
                    'INSERT INTO "%s" (%s) VALUES (%s);',
                    $table,
                    implode(', ', array_map(fn ($column) => '"'.$column.'"', array_keys((array) $row))),
                    implode(', ', $values),
                );
            }
        }

        File::put($path, implode("\n", [...$kept, ...$lines])."\n");

        return $stats;
    }
}
