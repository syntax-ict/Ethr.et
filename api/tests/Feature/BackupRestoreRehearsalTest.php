<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Backup\BackupService;
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

it('restores the database after it has been destroyed', function () {
    $tenant = createTenant(['subdomain' => 'habru']);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Almaz Tesfaye',
        'employee_code' => 'EMP-RESTORE-1',
        'salary_cents' => 1234500,
    ]);

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
