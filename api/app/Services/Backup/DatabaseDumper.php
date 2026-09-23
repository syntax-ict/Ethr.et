<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dumps the database to SQL without shelling out.
 *
 * `mysqldump` is the obvious tool and it is deliberately not required here.
 * Shared hosting frequently disables `proc_open`, `exec` and `shell_exec`
 * outright, and even where a shell exists `mysqldump` is often absent from PATH
 * — it is absent from the development machine this was written on. A backup
 * that only works where a binary happens to be installed is not a backup you
 * can promise anyone, so this reads through PDO like any other query.
 *
 * It is slower than mysqldump and makes no attempt to compete with it. It is
 * portable, which for the go-live gate in master plan §30 is the property that
 * matters.
 *
 * ## Three passes, not one
 *
 * Schema, then data, then triggers. Interleaving them breaks in two distinct
 * ways, both of which this hit before the order was fixed:
 *
 *  - An INSERT can reference a table the dump has not created yet. The header
 *    sets FOREIGN_KEY_CHECKS=0, but that is not something to rely on: the
 *    SQLite equivalent, `PRAGMA foreign_keys=OFF`, is silently ignored inside a
 *    transaction, and a dump that only restores under the right session flags
 *    is a dump with a trap in it.
 *  - Triggers fire on write. Creating them before the data runs every INSERT
 *    through them, and `audit_log`'s triggers exist specifically to reject
 *    writes.
 *
 * ## Triggers, and why they carry no DEFINER
 *
 * `audit_log` has two triggers that make it append-only. MySQL stores a DEFINER
 * with every trigger, defaulting to the user that created it. Restore a dump
 * under a different database user — which Plesk's own backup tooling and
 * phpMyAdmin routinely do — and the recorded definer no longer exists, at which
 * point *every* INSERT into `audit_log` fails. `AuditLog::record()` is called at
 * 206 sites inside the write transaction of nearly every mutating endpoint, so
 * the product's failure mode after such a restore is that all creates and
 * updates return 500.
 *
 * Emitting `CREATE TRIGGER` with no DEFINER clause makes the restoring user the
 * definer. That is both what you want and the only option on a host where you
 * cannot grant SUPER. See docs/audit/BASELINE.md §13b.
 */
class DatabaseDumper
{
    /** Rows read per batch. Keeps memory flat on tables the size of `attendance_records`. */
    private const CHUNK = 500;

    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Write the whole database to $path as SQL.
     *
     * @return array{tables: int, rows: int, triggers: int, bytes: int}
     */
    public function dumpTo(string $path): array
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path} for writing.");
        }

        try {
            $driver = DB::connection($this->connection)->getDriverName();

            $this->writeHeader($handle, $driver);

            $stats = match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($handle),
                'sqlite' => $this->dumpSqlite($handle),
                default => throw new RuntimeException(
                    "No dumper for driver [{$driver}]. Supported: mysql, mariadb, sqlite."
                ),
            };

            $this->writeFooter($handle, $driver);
        } finally {
            fclose($handle);
        }

        return [...$stats, 'bytes' => (int) filesize($path)];
    }

    /** @param  resource  $handle */
    private function writeHeader($handle, string $driver): void
    {
        fwrite($handle, "-- ETHR database dump\n");
        fwrite($handle, '-- Generated: '.now()->toIso8601String()."\n");
        fwrite($handle, "-- Driver: {$driver}\n");
        fwrite($handle, "-- Schema, then data, then triggers. Triggers carry no DEFINER on purpose.\n--\n\n");

        // Belt and braces. The three-pass ordering above is what actually makes
        // the dump restorable; this only helps where the session honours it.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        }
    }

    /** @param  resource  $handle */
    private function writeFooter($handle, string $driver): void
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        }
    }

    /**
     * @param  resource  $handle
     * @return array{tables: int, rows: int, triggers: int}
     */
    private function dumpMysql($handle): array
    {
        $db = DB::connection($this->connection);

        $tables = array_map(
            fn ($row) => array_values((array) $row)[0],
            $db->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')
        );

        // Pass 1 — schema.
        foreach ($tables as $table) {
            $create = (array) $db->selectOne("SHOW CREATE TABLE `{$table}`");
            $ddl = $create['Create Table'] ?? array_values($create)[1];

            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n");
        }

        // Pass 2 — data.
        $rows = 0;
        foreach ($tables as $table) {
            $rows += $this->writeRows($handle, $table);
        }

        // Pass 3 — triggers.
        $triggers = $this->dumpMysqlTriggers($handle);

        return ['tables' => count($tables), 'rows' => $rows, 'triggers' => $triggers];
    }

    /** @param  resource  $handle */
    private function dumpMysqlTriggers($handle): int
    {
        $db = DB::connection($this->connection);
        $triggers = $db->select('SHOW TRIGGERS');

        foreach ($triggers as $trigger) {
            $t = (array) $trigger;

            // No DEFINER clause, and no DELIMITER juggling: written as one
            // statement so a restore can execute it straight through PDO rather
            // than needing a client that understands DELIMITER.
            fwrite($handle, sprintf(
                "DROP TRIGGER IF EXISTS `%s`;\nCREATE TRIGGER `%s` %s %s ON `%s` FOR EACH ROW %s;\n\n",
                $t['Trigger'],
                $t['Trigger'],
                $t['Timing'],
                $t['Event'],
                $t['Table'],
                trim($t['Statement']),
            ));
        }

        return count($triggers);
    }

    /**
     * @param  resource  $handle
     * @return array{tables: int, rows: int, triggers: int}
     */
    private function dumpSqlite($handle): array
    {
        $db = DB::connection($this->connection);

        $objects = array_map(
            fn ($row) => (array) $row,
            $db->select(
                "SELECT name, type, sql FROM sqlite_master
                 WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'"
            )
        );

        // Pass 1 — schema.
        $tableNames = [];
        foreach ($objects as $object) {
            if ($object['type'] === 'table') {
                fwrite($handle, "DROP TABLE IF EXISTS \"{$object['name']}\";\n{$object['sql']};\n\n");
                $tableNames[] = $object['name'];
            }
        }

        // Pass 2 — data.
        $rows = 0;
        foreach ($tableNames as $name) {
            $rows += $this->writeRows($handle, $name);
        }

        // Pass 3 — indexes and triggers.
        $triggers = 0;
        foreach ($objects as $object) {
            if ($object['type'] !== 'table') {
                fwrite($handle, "{$object['sql']};\n\n");
                $triggers += $object['type'] === 'trigger' ? 1 : 0;
            }
        }

        return ['tables' => count($tableNames), 'rows' => $rows, 'triggers' => $triggers];
    }

    /**
     * Stream one table's rows as INSERT statements. Returns the row count.
     *
     * @param  resource  $handle
     */
    private function writeRows($handle, string $table): int
    {
        $db = DB::connection($this->connection);
        $quote = $db->getDriverName() === 'sqlite' ? '"' : '`';
        $written = 0;

        $columns = $this->writableColumns($table);

        if ($columns === []) {
            return 0;
        }

        $query = $db->table($table)->select($columns)->orderByRaw('1');

        $query->chunk(self::CHUNK, function ($chunk) use ($handle, $table, $quote, &$written) {
            foreach ($chunk as $row) {
                $columns = array_keys((array) $row);
                $values = array_map(fn ($value) => $this->literal($value), array_values((array) $row));

                fwrite($handle, sprintf(
                    "INSERT INTO %s%s%s (%s) VALUES (%s);\n",
                    $quote, $table, $quote,
                    implode(', ', array_map(fn ($c) => $quote.$c.$quote, $columns)),
                    implode(', ', $values),
                ));

                $written++;
            }
        });

        if ($written > 0) {
            fwrite($handle, "\n");
        }

        return $written;
    }

    /**
     * The columns of `$table` a restore is allowed to supply values for —
     * every column except the generated ones.
     *
     * `SELECT *` returns generated columns like any other, and replaying an
     * INSERT that names one is rejected outright: *"cannot INSERT into
     * generated column"* on SQLite, *"The value specified for generated column
     * … is not allowed"* on MariaDB. So a dump taken with `SELECT *` stops
     * being restorable the moment any table has a generated column **and at
     * least one row** — which is a backup that passes every check at the time
     * it is written and fails only when someone needs it.
     *
     * It was latent rather than theoretical: `devices.serial_number_active`
     * (2026-09-23, BASELINE §11h) already put one in the schema, and the
     * round-trip kept passing only because the tables under test had no device
     * rows. `users` and `employees` never have that luxury.
     *
     * The generated column itself is not lost — `SHOW CREATE TABLE` and
     * `sqlite_master.sql` both carry its definition into the restored schema,
     * which then recomputes the value from the columns that *are* replayed.
     *
     * @return array<int, string>
     */
    private function writableColumns(string $table): array
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'sqlite') {
            // `table_info` omits generated columns; `table_xinfo` is the pragma
            // that includes them, marked `hidden` 2 (virtual) or 3 (stored).
            return array_map(
                static fn ($row): string => (string) (((array) $row)['name'] ?? ''),
                $db->select("PRAGMA table_info('".str_replace("'", "''", $table)."')"),
            );
        }

        // `SHOW FULL COLUMNS` rather than `information_schema`: it needs only a
        // privilege on the table itself, which is what a shared-hosting account
        // is given. `Extra` reads 'VIRTUAL GENERATED' or 'STORED GENERATED'.
        $columns = [];

        foreach ($db->select("SHOW FULL COLUMNS FROM `{$table}`") as $row) {
            $described = (array) $row;

            if (str_contains(mb_strtoupper((string) ($described['Extra'] ?? '')), 'GENERATED')) {
                continue;
            }

            $columns[] = (string) ($described['Field'] ?? '');
        }

        return $columns;
    }

    /** Render one PHP value as a SQL literal. */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Binary-safe. Selfies, PDFs and anything else that is not valid UTF-8
        // would corrupt a plain quoted string, so those go out as hex literals,
        // which both MySQL and SQLite accept.
        if (! mb_check_encoding((string) $value, 'UTF-8')) {
            return '0x'.bin2hex((string) $value);
        }

        return $this->quoteFlat((string) $value);
    }

    /**
     * Guarantee a literal contains no raw newline.
     *
     * The dump's one-statement-per-line layout is not cosmetic — it is the
     * contract `BackupService::executeSqlFile()` relies on to know where a
     * statement ends. A value carrying a newline breaks that contract, and if
     * the text before the newline happens to end in a semicolon the restore
     * executes half an INSERT and then fails on the remainder. An employee note
     * reading "see clause 4; \nsigned" is enough.
     *
     * PDO's own quoting does not close this uniformly, which is the trap:
     *
     *   mysql   quote("a; \nb")  ->  'a; \nb'        newline escaped, safe
     *   sqlite  quote("a; \nb")  ->  'a; <LF> b'     newline literal, TEARS
     *
     * So the MySQL path — production — was already correct, and the SQLite path
     * — every backup test in this repository — was not. A driver difference
     * that makes the *tested* path the weaker one is exactly the shape that
     * survives a green suite, so this normalises both rather than special-casing
     * the one known to be broken.
     *
     * `char(N)` concatenation is used instead of a backslash escape because
     * SQLite string literals have no backslash escapes at all: '\n' there is a
     * two-character string, which would silently corrupt the value rather than
     * tear the statement. Both drivers accept `char()` and `||`.
     *
     * The split happens on the RAW value and each fragment is quoted
     * separately. Splitting the already-quoted string instead would leave the
     * opening quote on the first fragment and the closing quote on the last,
     * with everything between unterminated — a subtler corruption than the one
     * being fixed.
     */
    private function quoteFlat(string $value): string
    {
        $pdo = DB::connection($this->connection)->getPdo();

        if (! str_contains($value, "\n") && ! str_contains($value, "\r")) {
            return $pdo->quote($value);
        }

        $parts = preg_split('/(\r\n|\r|\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = [];

        foreach ($parts as $part) {
            $piece = match ($part) {
                "\r\n" => 'char(13)||char(10)',
                "\r" => 'char(13)',
                "\n" => 'char(10)',
                // An empty fragment is what a leading, trailing or doubled
                // newline produces. Dropping it would silently lose nothing;
                // quoting it would emit a pointless ''. Skip it.
                '' => null,
                default => $pdo->quote($part),
            };

            if ($piece !== null) {
                $out[] = $piece;
            }
        }

        // A value that is nothing but newlines leaves no quoted fragment, and
        // char(10) alone is already a valid string expression.
        return $out === [] ? "''" : implode('||', $out);
    }
}
