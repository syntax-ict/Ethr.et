<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll run gains a last state, `paid`, and its amounts are frozen in the
 * database once it is released.
 *
 * Until now only `calculation_log` was immutable after creation
 * (PayrollEntry::booted). The amount columns had no guard at all: a mass
 * update, a raw query or a tinker session could change what an approved
 * payslip says while its log said something else. Model guards cannot stop
 * those paths, as the audit log learned (2026_07_22_000001), so the rule
 * lives in triggers:
 *
 * - `payroll_entries_amounts_frozen`: an entry's amounts, its employee and
 *   its log cannot change once its run is approved, paid or voided.
 * - `payroll_runs_totals_frozen`: a released run's totals and period cannot
 *   change; a voided run stays voided; a paid run stays paid (a correction
 *   goes through the next run, owner decision Q6); and only an approved run
 *   can become paid.
 *
 * There is no DELETE trigger: hard-deleting a tenant cascades through these
 * tables, and the models' NeverDelete already refuses a delete on its own.
 *
 * Fatal on failure, like the audit-log triggers: a deployment that cannot
 * freeze released payroll must stop and say so.
 */
return new class extends Migration
{
    private const MESSAGE_ENTRY = 'payroll entry amounts are frozen once the run is released';

    private const MESSAGE_RUN = 'a released payroll run cannot change its totals or go back';

    private const ENTRY_COLUMNS = [
        'payroll_run_id', 'employee_id', 'basic_salary_cents', 'allowances', 'deductions', 'gross_cents',
        'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents', 'other_deductions_cents',
        'net_cents', 'calculation_log',
    ];

    private const RUN_COLUMNS = [
        'gross_total_cents', 'net_total_cents', 'tax_total_cents', 'employee_count', 'period_start', 'period_end',
    ];

    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->date('paid_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 120)->nullable();
            $table->string('payment_note', 500)->nullable();
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($this->mariadbTriggers() as $name => $statement) {
                try {
                    DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                    DB::unprepared($statement);
                } catch (Throwable $e) {
                    $message = "Trigger {$name} could NOT be created: the database user lacks the TRIGGER privilege "
                        .'(or SUPER, required when binary logging is enabled). The migration is being ABORTED: ETHR '
                        ."will not deploy without frozen released payroll. Have a DBA run:\n    {$statement};";
                    Log::warning($message, ['error' => $e->getMessage()]);
                    if (PHP_SAPI === 'cli') {
                        fwrite(STDERR, "\n  WARNING: {$message}\n\n");
                    }

                    throw $e;
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach ($this->sqliteTriggers() as $name => $statement) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                DB::unprepared($statement);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (['payroll_entries_amounts_frozen', 'payroll_runs_totals_frozen'] as $name) {
            try {
                DB::unprepared($driver === 'sqlite' ? "DROP TRIGGER IF EXISTS {$name}" : "DROP TRIGGER IF EXISTS `{$name}`");
            } catch (Throwable $e) {
                Log::warning("Could not drop trigger {$name}", ['error' => $e->getMessage()]);
            }
        }

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['paid_on', 'paid_at', 'payment_reference', 'payment_note']);
        });
    }

    /** @return array<string, string> */
    private function mariadbTriggers(): array
    {
        $entryChanged = implode(' OR ', array_map(fn (string $c) => "NOT (NEW.`{$c}` <=> OLD.`{$c}`)", self::ENTRY_COLUMNS));
        $runChanged = implode(' OR ', array_map(fn (string $c) => "NOT (NEW.`{$c}` <=> OLD.`{$c}`)", self::RUN_COLUMNS));

        return [
            'payroll_entries_amounts_frozen' => 'CREATE TRIGGER `payroll_entries_amounts_frozen` BEFORE UPDATE ON `payroll_entries` FOR EACH ROW '
                ."BEGIN IF (SELECT `status` FROM `payroll_runs` WHERE `id` = OLD.`payroll_run_id`) IN ('approved', 'paid', 'voided') "
                ."AND ({$entryChanged}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE_ENTRY."'; END IF; END",
            'payroll_runs_totals_frozen' => 'CREATE TRIGGER `payroll_runs_totals_frozen` BEFORE UPDATE ON `payroll_runs` FOR EACH ROW '
                ."BEGIN IF (OLD.`status` IN ('approved', 'paid', 'voided') AND ({$runChanged})) "
                ."OR (OLD.`status` = 'voided' AND NEW.`status` <> 'voided') "
                ."OR (OLD.`status` = 'paid' AND NEW.`status` <> 'paid') "
                ."OR (NEW.`status` = 'paid' AND OLD.`status` NOT IN ('approved', 'paid')) "
                ."THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE_RUN."'; END IF; END",
        ];
    }

    /** @return array<string, string> */
    private function sqliteTriggers(): array
    {
        $entryChanged = implode(' OR ', array_map(fn (string $c) => "NEW.{$c} IS NOT OLD.{$c}", self::ENTRY_COLUMNS));
        $runChanged = implode(' OR ', array_map(fn (string $c) => "NEW.{$c} IS NOT OLD.{$c}", self::RUN_COLUMNS));

        return [
            'payroll_entries_amounts_frozen' => 'CREATE TRIGGER payroll_entries_amounts_frozen BEFORE UPDATE ON payroll_entries '
                ."WHEN (SELECT status FROM payroll_runs WHERE id = OLD.payroll_run_id) IN ('approved', 'paid', 'voided') "
                ."AND ({$entryChanged}) BEGIN SELECT RAISE(ABORT, '".self::MESSAGE_ENTRY."'); END",
            'payroll_runs_totals_frozen' => 'CREATE TRIGGER payroll_runs_totals_frozen BEFORE UPDATE ON payroll_runs '
                ."WHEN (OLD.status IN ('approved', 'paid', 'voided') AND ({$runChanged})) "
                ."OR (OLD.status = 'voided' AND NEW.status <> 'voided') "
                ."OR (OLD.status = 'paid' AND NEW.status <> 'paid') "
                ."OR (NEW.status = 'paid' AND OLD.status NOT IN ('approved', 'paid')) "
                ."BEGIN SELECT RAISE(ABORT, '".self::MESSAGE_RUN."'); END",
        ];
    }
};
