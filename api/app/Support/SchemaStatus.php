<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;

/**
 * How far the database is behind the code it serves.
 *
 * A deploy and its `migrate` are two steps on the shared host: Plesk Git
 * puts the code in place, then the token-guarded maintenance endpoint runs
 * the migration. Nothing outside the server could tell when the second step
 * was skipped. On 2026-11-04 v2026.11.04.5 went live at 05:33Z without it,
 * and a migration merged the day before, with the permission seed of #561,
 * waited until 10:46Z while the code read columns that did not exist
 * (docs/audit/BASELINE.md §25f records the rule: deploy and migrate are one
 * step). `/health` now reports the count, and scripts/release-status.sh
 * fails while it is above zero.
 *
 * Only a count leaves the server: the names of pending migrations would say
 * what the schema is about to become, and the number is all a check needs.
 */
final class SchemaStatus
{
    public function __construct(private readonly Migrator $migrator) {}

    /** Migrations on disk that the database has not run; null when that cannot be read. */
    public function pendingMigrations(): ?int
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return null;
            }

            // The paths `migrate` itself reads: the package-registered ones
            // and database/migrations.
            $files = $this->migrator->getMigrationFiles(
                array_merge($this->migrator->paths(), [database_path('migrations')]),
            );
            $ran = $this->migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (\Throwable) {
            return null;
        }
    }
}
