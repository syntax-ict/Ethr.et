<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\PreFixDumper;

/**
 * What each engine actually does with a dump that names a generated column —
 * measured, because the first account of this was inferred and was wrong.
 *
 * §15f originally said replaying such an INSERT fails on MariaDB as it does on
 * SQLite, and that the MariaDB rehearsal job passed on `main` only because no
 * fixture held a device row. Both halves were wrong: `DemoTenantSeeder` creates
 * three devices, the rehearsal job seeds it, and the job passed anyway. So
 * MariaDB accepted what SQLite refuses, and the claim had been reasoned from
 * the SQLite result rather than read off the MariaDB one.
 *
 * This file exists so that stops being a matter of recollection. It runs on
 * both drivers and pins:
 *
 *  - SQLite rejects the statement outright;
 *  - MariaDB accepts it under the `sql_mode` this repository's `mariadb`
 *    connection sets (`'strict' => false` in config/database.php, which every
 *    `.env` template selects) and rejects it under a strict `sql_mode`;
 *  - the guard flags it on **both**, which is the point. A backup whose
 *    replayability depends on the `sql_mode` of whoever restores it is not a
 *    backup you can rely on, even where it happens to work.
 *
 * No DDL: it writes to `users`, which already carries generated columns, so
 * nothing here implicitly commits and breaks the surrounding transaction the
 * way a `CREATE TABLE` would on MySQL.
 */
beforeEach(function () {
    config(['backup.path' => storage_path('framework/testing/generated-column-backups')]);
    File::deleteDirectory(config('backup.path'));
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
});

function attemptGeneratedColumnInsert(): ?string
{
    $quote = DB::connection()->getDriverName() === 'sqlite' ? '"' : '`';
    $q = fn (string $column): string => $quote.$column.$quote;

    try {
        DB::insert(sprintf(
            'INSERT INTO %s (%s, %s, %s, %s) VALUES (?, ?, ?, ?)',
            $q('users'),
            $q('public_id'),
            $q('email'),
            $q('password'),
            $q('email_normalized'),
        ), [(string) Str::ulid(), 'Replay@Acme.test', 'x', 'replay@acme.test']);

        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

it('records what this engine does with an INSERT naming a generated column', function () {
    $driver = DB::connection()->getDriverName();
    $error = attemptGeneratedColumnInsert();

    if ($driver === 'sqlite') {
        expect($error)->toContain('cannot INSERT into generated column');

        return;
    }

    // MariaDB, on the connection this repository actually uses. `strict` is
    // false there — see config/database.php — and the statement is accepted
    // with the supplied value discarded and the column recomputed.
    expect($error)->toBeNull(
        'MariaDB rejected an INSERT naming a generated column on the default connection. '
        ."That contradicts the rehearsal job's green run on main at 8b11904, which seeded three "
        .'devices and restored successfully. Re-read BASELINE.md §15f: its account of this is '
        .'what this test exists to keep honest. Driver said: '.(string) $error
    );

    // And the same statement under a strict sql_mode, which is what a restore
    // run through phpMyAdmin, a Plesk import, or DB_CONNECTION=mysql may carry.
    $previous = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

    try {
        DB::statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

        expect(attemptGeneratedColumnInsert())->toContain('generated column');
    } finally {
        DB::statement('SET SESSION sql_mode = ?', [$previous]);
    }
});

it('flags the statement on every driver, whatever the engine would do with it', function () {
    // The guard does not consult the engine, and that is deliberate: the
    // question is not "will this restore here" but "can this be restored
    // anywhere it might need to be".
    $path = storage_path('framework/testing/generated-column-dump.sql');
    File::ensureDirectoryExists(dirname($path));

    $quote = DB::connection()->getDriverName() === 'sqlite' ? '"' : '`';

    File::put($path, sprintf(
        "INSERT INTO %susers%s (%spublic_id%s, %semail%s, %semail_normalized%s) VALUES ('x', 'y', 'z');\n",
        $quote, $quote, $quote, $quote, $quote, $quote, $quote, $quote,
    ));

    try {
        expect(app(DatabaseDumper::class)->findGeneratedColumnInserts($path))
            ->toBe(['users' => ['email_normalized']]);
    } finally {
        File::delete($path);
    }
});

it('refuses to write a backup whose INSERTs name a generated column', function () {
    // The check the manifest cannot do. sha256 proves the file is the one that
    // was written; it says nothing about whether the file can be put back, and
    // that gap is precisely what shipped in §15f — written without complaint,
    // hashing correctly, rejected only at restore time.
    //
    // Proven the only way a guard can be: against a dump produced the way the
    // pre-fix dumper produced them. `PreFixDumper` is `writeRows()` as it stood
    // before 2026-09-23 — `SELECT *`, then an INSERT naming every column it got
    // back — so this fails against the old code and passes against the new one,
    // rather than passing against both.
    //
    // **This test lives here, not in `BackupRestoreRehearsalTest`, so that it
    // runs on MariaDB as well as SQLite.** It was written there first, and that
    // file skips on any other driver because its other tests drop every table —
    // a hazard this one does not share, since it destroys nothing. The effect
    // was that the guard was proven to fire on SQLite and merely assumed to
    // fire on the engine production actually uses.
    //
    // `createUser()` is enough of a fixture: `users` carries three generated
    // columns, so one row is all the pre-fix dumper needs to emit an INSERT
    // that names one.
    createUser();

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
