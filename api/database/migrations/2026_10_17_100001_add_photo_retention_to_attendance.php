<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance selfies are kept for a set time and then deleted, the record
 * itself kept (docs/operations/ATTENDANCE-PHOTO-RETENTION.md).
 *
 * `photo_retention_days` is each organisation's choice, 7 by default. On the
 * record: when the 48-hour review began (HR and the department manager were
 * told), a hold for an unresolved complaint, and when the photos were deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('photo_retention_days')->default(7);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->timestamp('photo_review_started_at')->nullable();
            $table->timestamp('photo_held_at')->nullable();
            $table->foreignId('photo_held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_hold_reason', 500)->nullable();
            $table->timestamp('photos_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photo_held_by');
            $table->dropColumn(['photo_review_started_at', 'photo_held_at', 'photo_hold_reason', 'photos_deleted_at']);
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('photo_retention_days');
        });
    }
};
