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
 * `BackupRestoreRehearsalTest`'s *"refuses to write a backup whose INSERTs name
 * a generated column"* measures the real regression instead of a
 * plausible-looking imitation of it. It overrides only the data pass; schema
 * and triggers come from the parent unchanged, because those halves were never
 * the defect.
 *
 * SQLite-only, like the test that binds it — `sqlite_master` and
 * `PRAGMA table_xinfo` below say so plainly.
 */
final class PreFixDumper extends DatabaseDumper
{
    /**
     * The generated columns of every table, read from the live schema.
     *
     * A local copy of the Pest file's helper: this class lives outside that
     * file so `composer dump-autoload` stops warning that a PSR-4 rule cannot
     * see it, and a global function declared in a test file is not in scope
     * here.
     *
     * @return array<string, array<int, string>>
     */
    public static function tablesWithGeneratedColumns(): array
    {
        $found = [];

        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables as $table) {
            $name = (string) $table->name;

            $generated = [];

            foreach (DB::select("PRAGMA table_xinfo('".str_replace("'", "''", $name)."')") as $column) {
                if ((int) $column->hidden !== 0) {
                    $generated[] = (string) $column->name;
                }
            }

            if ($generated !== []) {
                $found[$name] = $generated;
            }
        }

        return $found;
    }

    public function dumpTo(string $path): array
    {
        $stats = parent::dumpTo($path);

        $tables = array_keys(self::tablesWithGeneratedColumns());

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
