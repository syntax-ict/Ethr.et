<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance rules a branch sets differently from its organisation.
 *
 * `attendance_settings` is one row per tenant, so a head office that wanted a
 * geofence and a selfie and a field depot that could not use either had to
 * pick one rule for both. An override holds only the keys the branch changes
 * (AttendancePolicy::OVERRIDABLE); everything else is inherited, so a change
 * at the organisation still reaches the branch.
 *
 * Organisation, then branch, then the employee's mode, and nothing deeper:
 * departments keep only shifts. Every extra level makes the answer to "which
 * rule applies to this person?" harder to predict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->json('overrides');
            $table->timestamps();

            $table->unique('branch_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_attendance_overrides');
    }
};
