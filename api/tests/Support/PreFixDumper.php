<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * `DatabaseDumper` as it stood before 2026-09-23 — data written from
 * `SELECT *`, so every INSERT names the generated columns too.
 *
 * Copied from the commit that replaced it rather than hand-written, so
 * *"it refuses to write a backup whose INSERTs name a generated column"*
 * measures the real regression instead of a plausible-looking imitation of it.
 * It overrides only the data pass; schema and triggers come from the parent
 * unchanged, because those halves were never the defect.
 *
 * **Driver-independent, deliberately.** An earlier version read `sqlite_master`
 * and `PRAGMA table_xinfo` and quoted identifiers with `"`, which pinned the
 * guard's end-to-end firing to SQLite for no reason that was ever inherent —
 * the parent already classifies columns per driver, and the quote character is
 * one expression. The guard's throw path is the same code on both engines, and
 * a guard proven on one engine and assumed on the other is the shape of defect
 * this whole class exists to catch.
 */
final class PreFixDumper extends DatabaseDumper
{
    /**
     * Every table carrying a generated column, read from the live schema.
     *
     * Lives here rather than as a function inside a Pest file so the
     * autoloader can see it, and so the two test files that need it share one
     * definition. Column classification is delegated to `DatabaseDumper`,
     * which already does it per driver.
     *
     * @return array<string, array<int, string>> table => generated columns
     */
    public static function tablesWithGeneratedColumns(): array
    {
        $dumper = new DatabaseDumper;
        $found = [];

        foreach (self::tableNames() as $table) {
            $generated = $dumper->generatedColumns($table);

            if ($generated !== []) {
                $found[$table] = $generated;
            }
        }

        return $found;
    }

    public function dumpTo(string $path): array
    {
        $stats = parent::dumpTo($path);

        $quote = DB::connection()->getDriverName() === 'sqlite' ? '"' : '`';
        $tables = array_keys(self::tablesWithGeneratedColumns());

        // Drop the fixed dumper's INSERTs for those tables, then write them the
        // old way. Replacing rather than appending matters: a dump carrying
        // both shapes is not a dump the pre-fix code could ever have produced,
        // and a check that only looked at the first row per table would score
        // it clean.
        $kept = array_filter(
            explode("\n", (string) File::get($path)),
            function (string $line) use ($tables, $quote): bool {
                foreach ($tables as $table) {
                    if (str_starts_with($line, 'INSERT INTO '.$quote.$table.$quote.' (')) {
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

                $columns = array_map(
                    fn ($column) => $quote.$column.$quote,
                    array_keys((array) $row),
                );

                $lines[] = sprintf(
                    'INSERT INTO %s%s%s (%s) VALUES (%s);',
                    $quote,
                    $table,
                    $quote,
                    implode(', ', $columns),
                    implode(', ', $values),
                );
            }
        }

        File::put($path, implode("\n", [...$kept, ...$lines])."\n");

        return $stats;
    }

    /**
     * Base table names, per driver — the same two queries `DatabaseDumper`
     * uses to decide what to dump.
     *
     * @return array<int, string>
     */
    private static function tableNames(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return array_map(
                static fn ($row): string => (string) (((array) $row)['name'] ?? ''),
                DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            );
        }

        return array_map(
            static fn ($row): string => (string) (array_values((array) $row)[0] ?? ''),
            DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'),
        );
    }
}
