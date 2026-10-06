<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings leave_requests, leave_types, grades, employee_documents, and shifts
 * in line with the CLAUDE.md soft-delete policy table. leave_requests, grades,
 * employee_documents, and shifts are explicitly documented as "Soft delete"
 * but were missing deleted_at entirely. leave_types isn't named in the policy
 * table directly, but leave_requests/leave_balances reference it by FK — the
 * same historical-integrity rationale documented for departments/positions/
 * branches applies, and it already has a live hard-delete route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
