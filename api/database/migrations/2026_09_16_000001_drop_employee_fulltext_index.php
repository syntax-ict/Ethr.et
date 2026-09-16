<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `emp_search`, the FULLTEXT index nothing reads any more.
 *
 * `2026_07_16_000003_add_employee_fulltext_index` added it for
 * `Employee::scopeSearch()`, which had a MySQL branch using
 * `MATCH … AGAINST`. That branch was deleted on 2026-09-15 (D-012): it was the
 * only code path in the application that had never been executed by a test —
 * the suite runs SQLite, which took the `LIKE` branch — and it was wrong.
 * Stripping boolean operators from the term turned `EMP-1234` into `EMP1234`,
 * which matches no token, so searching an employee's code in full found
 * nobody in production and passed every test.
 *
 * `scopeSearch()` now uses `LIKE` on every driver, so the tested path is the
 * production path. A grep for `AGAINST` and `whereFullText` across `app/`,
 * `routes/`, `database/` and `tests/` finds only comments describing that
 * history. The index has no reader.
 *
 * An unused FULLTEXT index is not free. InnoDB maintains it on every insert and
 * on every update touching `name`, `name_am`, `email` or `employee_code` —
 * which is most employee writes, including bulk imports — and it carries its
 * own auxiliary tables. It also misleads: a FULLTEXT index on `employees`
 * implies a fulltext search that no longer exists.
 *
 * ## Reversing this
 *
 * `down()` recreates it exactly. But note what D-012 actually says about the
 * revisit condition — search is `LIKE` and costs ~13ms at 5,000 employees in
 * one tenant against a 100ms budget; past roughly 40,000 the answer named there
 * is a prefix or trigram index, or a search service. **Not** a return to the
 * old branch, which is what this index existed to serve. So `down()` is here
 * for rollback, not as a plan.
 *
 * SQLite is skipped, as it was on the way in: the original migration never
 * created this index there, and SQLite has no `FULLTEXT`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->applies()) {
            return;
        }

        // Guarded because migrations run in order on a fresh database: the 2026-07-16
        // migration adds the index and this one removes it moments later. Guarded
        // anyway, so a database where it was already dropped by hand does not fail here.
        if ($this->indexExists()) {
            DB::statement('ALTER TABLE `employees` DROP INDEX `emp_search`');
        }
    }

    public function down(): void
    {
        if (! $this->applies() || $this->indexExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `employees` ADD FULLTEXT INDEX `emp_search` (`name`, `name_am`, `email`, `employee_code`)'
        );
    }

    private function applies(): bool
    {
        return Schema::hasTable('employees') && DB::getDriverName() !== 'sqlite';
    }

    private function indexExists(): bool
    {
        return DB::select("SHOW INDEX FROM `employees` WHERE Key_name = 'emp_search'") !== [];
    }
};
