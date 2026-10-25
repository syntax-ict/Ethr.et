<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risk scoring for punches (AttendanceRiskScorer).
 *
 * `risk_score` (0-100) and `risk_reasons` sit on the record so the review
 * queue can sort by them and a payroll run can list what is still open.
 * `device_hash` is a column, not just metadata, because "has this install
 * punched for anyone else lately?" is asked on every punch and needs an
 * index. Two settings: the score that sends a punch to review, and
 * adaptive selfies (fewer for staff with a clean record, always after a
 * finding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->json('risk_reasons')->nullable();
            $table->char('device_hash', 32)->nullable();

            $table->index(['tenant_id', 'device_hash', 'created_at'], 'att_rec_device_idx');
        });

        Schema::table('attendance_integrity_flags', function (Blueprint $table) {
            $table->index(['tenant_id', 'employee_id', 'created_at'], 'att_flag_employee_idx');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('risk_review_threshold')->default(40);
            $table->boolean('adaptive_selfie')->default(false);
        });

    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['risk_review_threshold', 'adaptive_selfie']);
        });
        Schema::table('attendance_integrity_flags', function (Blueprint $table) {
            $table->dropIndex('att_flag_employee_idx');
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('att_rec_device_idx');
            $table->dropColumn(['risk_score', 'risk_reasons', 'device_hash']);
        });
    }
};
