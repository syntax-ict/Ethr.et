<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attendance correction gains a kind, so the one request already on the
 * approval engine covers the two things payroll staff reconciled by hand.
 *
 *  - `missed_punch`: the employee was at work on a day with no record. There
 *    is no record to point at, so `attendance_record_id` becomes nullable and
 *    the day is stated in `date`. Approving it creates the record.
 *  - `lateness`: a late arrival or early departure, and how many of its
 *    minutes payroll deducts (0 waives it). It changes no punch. The
 *    organisation must opt in first and record why it may deduct
 *    (Proclamation 1156/2019 Art. 59), so the switch lives on
 *    `attendance_settings` beside the other organisation-wide rules.
 *
 * Every existing correction is a `correction`, which is what the default says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table): void {
            $table->foreignId('attendance_record_id')->nullable()->change();
            $table->string('kind', 20)->default('correction')->after('employee_id');
            $table->date('date')->nullable()->after('kind');
            $table->unsignedSmallInteger('deduct_minutes')->nullable()->after('proposed_check_out');

            $table->index(['tenant_id', 'employee_id', 'date'], 'attendance_corrections_employee_date');
        });

        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->boolean('lateness_deduction_enabled')->default(false)->after('overtime_requires_approval');
            $table->string('lateness_deduction_basis', 500)->nullable()->after('lateness_deduction_enabled');
        });
    }

    /**
     * Refuses when a missed-punch claim exists: it has no record, and putting
     * NOT NULL back would either fail or need a record that never existed.
     */
    public function down(): void
    {
        if (DB::table('attendance_corrections')->whereNull('attendance_record_id')->exists()) {
            throw new RuntimeException('Cannot make attendance_corrections.attendance_record_id NOT NULL: missed-punch claims exist.');
        }

        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn(['lateness_deduction_enabled', 'lateness_deduction_basis']);
        });

        Schema::table('attendance_corrections', function (Blueprint $table): void {
            $table->dropIndex('attendance_corrections_employee_date');
            $table->dropColumn(['kind', 'date', 'deduct_minutes']);
        });

        Schema::table('attendance_corrections', function (Blueprint $table): void {
            $table->foreignId('attendance_record_id')->nullable(false)->change();
        });
    }
};
