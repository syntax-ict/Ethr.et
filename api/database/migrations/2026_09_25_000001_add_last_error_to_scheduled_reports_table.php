<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A failed scheduled report was indistinguishable from a delivered one.
 *
 * `RunScheduledReportsJob::handle()` catches per-schedule failures so one
 * tenant's broken report definition cannot stop everyone else's — correct, and
 * it must stay. But the catch then wrote `last_run_at` and `next_run_at` with
 * exactly the values the success path writes, so the only record of the failure
 * was a `Log::error` line nobody reads. From the API and the UI the schedule
 * reported a successful run at the moment it had in fact delivered nothing.
 *
 * That is the failure mode this codebase keeps rediscovering: not an error, but
 * an error that renders as success. `docs/CLAUDE.md`'s queue-recovery table
 * specifies "mark export as failed, notify requesting user" for the `exports`
 * queue and recorded, on 2026-09-22, that neither half existed.
 *
 * One nullable column rather than a status enum: NULL means the last run
 * succeeded, non-NULL means it failed and carries why. There is no third state
 * to represent — a schedule that has never run has `last_run_at` NULL — and an
 * enum would add a vocabulary that could drift from the code that writes it.
 *
 * `text` rather than `string`: this holds an exception message, which has no
 * useful length bound. The job truncates before writing (see its `MAX_ERROR`),
 * so the column is a backstop, not the limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
