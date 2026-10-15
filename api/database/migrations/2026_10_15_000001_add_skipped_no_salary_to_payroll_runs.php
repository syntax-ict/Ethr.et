<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many active employees a run skipped because nothing stated a salary
     * for them. Until 2026-10-15 that number existed nowhere: PayrollEngine
     * returned null per employee and the run page could not say why someone
     * was missing (production QA, F1). Backfilled as 0, meaning "not measured"
     * for runs computed before the column existed.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->unsignedInteger('skipped_no_salary')->default(0)->after('employee_count');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('skipped_no_salary');
        });
    }
};
