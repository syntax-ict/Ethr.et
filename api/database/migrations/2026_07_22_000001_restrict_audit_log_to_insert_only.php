<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enforces convention #5 (immutable audit log) in the database itself.
 *
 * This previously tried to REVOKE UPDATE/DELETE on `audit_log` from the app's own
 * database user. That approach could not work on MySQL/MariaDB and had never
 * actually been in force:
 *
 * - **A table-level REVOKE cannot subtract from a database-level GRANT.** The
 *   project's own compose file creates the user via `MARIADB_USER`/`MARIADB_DATABASE`,
 *   which produces `GRANT ALL PRIVILEGES ON \`ethr\`.*`. Against that,
 *   `REVOKE ... ON \`ethr\`.\`audit_log\`` fails with *"There is no such grant
 *   defined"* — there is no per-table grant to take away.
 * - **It also required privilege-admin rights**, which a correctly configured
 *   least-privilege app user does not hold, so it raised `1142 GRANT command denied`
 *   and aborted the entire migration run before any schema existed.
 * - It read `config('database.connections.mysql.*')` while the app runs on the
 *   `mariadb` connection, so on any deployment configuring those blocks differently
 *   it would have aimed at the wrong user or database.
 *
 * Triggers enforce the same rule and are immune to all three problems: they work
 * regardless of how privileges were granted, need only the TRIGGER privilege the
 * app user already has, and live with the table rather than with a grant.
 *
 * PostgreSQL keeps the REVOKE — its privileges *are* per-table, so it works there.
 */
return new class extends Migration
{
    private const GUARDS = [
        'audit_log_no_update' => 'UPDATE',
        'audit_log_no_delete' => 'DELETE',
    ];

    private const MESSAGE = 'audit_log is append-only (ETHR convention #5)';

    public function up(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $statements = [];

            foreach (self::GUARDS as $name => $operation) {
                $statements[$name] =
                    "CREATE TRIGGER `{$name}` BEFORE {$operation} ON `audit_log` FOR EACH ROW ".
                    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'";
            }

            // FATAL on failure — deliberately, and this is a security decision
            // rather than an oversight. See docs/AUDIT_LOG_INTEGRITY_DECISION.md.
            //
            // An earlier revision of this file downgraded the failure to
            // "log and continue", so a host that denies CREATE TRIGGER could
            // still deploy. That was wrong: `AuditLog`'s update()/delete()
            // overrides are plain instance methods, so every mass-operation path
            // — DB::table('audit_log')->update(), AuditLog::where(...)->delete(),
            // raw SQL — bypasses them. The trigger is the only thing that stops
            // an audit record being edited, and tests/Feature/AuditLogImmutabilityTest
            // asserts exactly that by driving the raw query builder.
            //
            // Continuing past this point would therefore put production in a
            // state the test suite contradicts, while CI stayed green because CI
            // runs SQLite, where the trigger IS created. A deployment that cannot
            // guarantee an immutable audit log must stop and say so.
            //
            // The catch below does not swallow the failure — it adds the
            // diagnosis and rethrows, because a bare SQLSTATE 42000 tells the
            // operator nothing about which privilege is missing or how to fix it.
            foreach ($statements as $name => $statement) {
                try {
                    DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                    DB::unprepared($statement);
                } catch (Throwable $e) {
                    $this->reportUnenforceable($statements, $e);

                    throw $e;
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach (self::GUARDS as $name => $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                DB::unprepared(
                    "CREATE TRIGGER {$name} BEFORE {$operation} ON audit_log ".
                    "BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"
                );
            }

            return;
        }

        if ($driver === 'pgsql') {
            // Postgres grants are per-table, so the revoke is meaningful here. It
            // still needs privilege-admin rights, so a failure is reported rather
            // than allowed to abort the deployment.
            $name = $connection->getName();
            $dbUser = config("database.connections.{$name}.username");
            $statement = sprintf('REVOKE UPDATE, DELETE ON audit_log FROM "%s"', $dbUser);

            try {
                DB::unprepared($statement);
            } catch (Throwable $e) {
                $message = 'Audit-log privilege change could not be applied — the database user '
                    ."lacks privilege-admin rights. Run this as an administrator:\n    {$statement};";

                Log::warning($message, ['error' => $e->getMessage()]);

                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, "\n  WARNING: {$message}\n\n");
                }
            }
        }
    }

    /**
     * Explain a trigger-creation failure before it is rethrown.
     *
     * The migration still aborts — this only replaces an opaque SQLSTATE with
     * the actual cause and the SQL a DBA needs. Called immediately before
     * `throw`, never instead of it.
     *
     * @param  array<string, string>  $statements
     */
    private function reportUnenforceable(array $statements, Throwable $e): void
    {
        // Each CREATE is paired with its DROP so the block is safe to paste
        // whatever partial state the failed run left behind. Privilege is
        // normally all-or-nothing, so the usual case is that none were created —
        // but if the first succeeded and the second was refused, an unguarded
        // CREATE would fail on "trigger already exists" and read to the DBA like
        // a second, unrelated problem.
        $sql = '';

        foreach ($statements as $name => $statement) {
            $sql .= "DROP TRIGGER IF EXISTS `{$name}`;\n    {$statement};\n    ";
        }

        $sql = rtrim($sql);

        $message = 'audit_log append-only triggers could NOT be created — the database user lacks '
            .'the TRIGGER privilege (or SUPER, required when binary logging is enabled). '
            ."The migration is being ABORTED: ETHR will not deploy without a database-enforced immutable audit log. \n"
            ."Have a DBA run:\n    ".$sql;

        Log::warning($message, ['error' => $e->getMessage()]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "\n  WARNING: {$message}\n\n");
        }
    }

    public function down(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            foreach (array_keys(self::GUARDS) as $name) {
                try {
                    DB::unprepared($driver === 'sqlite'
                        ? "DROP TRIGGER IF EXISTS {$name}"
                        : "DROP TRIGGER IF EXISTS `{$name}`");
                } catch (Throwable $e) {
                    // `IF EXISTS` suppresses "no such trigger", not "no such
                    // privilege". Where up() could not create the triggers, this
                    // cannot drop them either — and a rollback that throws on a
                    // trigger that was never created would strand the migration
                    // in a state neither `migrate` nor `migrate:rollback` can
                    // leave, which is worse than the thing it is guarding.
                    Log::warning("Could not drop trigger {$name}", ['error' => $e->getMessage()]);
                }
            }

            return;
        }

        if ($driver === 'pgsql') {
            $name = $connection->getName();
            $dbUser = config("database.connections.{$name}.username");

            try {
                DB::unprepared(sprintf('GRANT UPDATE, DELETE ON audit_log TO "%s"', $dbUser));
            } catch (Throwable $e) {
                Log::warning('Could not restore audit_log privileges', ['error' => $e->getMessage()]);
            }
        }
    }
};
