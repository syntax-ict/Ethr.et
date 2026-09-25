<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same defect `scheduled_reports.last_error` closed, in the sibling job.
 *
 * `RunDashboardDigestsJob::handle()` catches per-digest failures — correct, and
 * it must stay, or a permanently broken digest retries on every scheduler tick
 * forever — and then advanced `last_run_at` and `next_run_at` with exactly the
 * values the success path writes. A digest that delivered nothing read as
 * delivered.
 *
 * The two jobs were written to share a shape and not code (see
 * `RunDashboardDigestsJob`'s own header), so the defect was duplicated the day
 * the second one was written. Same column, same semantics: NULL means the last
 * run succeeded, non-NULL means it failed and carries why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_digests', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_digests', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
