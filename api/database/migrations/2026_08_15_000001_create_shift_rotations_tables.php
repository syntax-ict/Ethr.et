<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift rotations: repeating multi-week patterns.
 *
 * A `shift` already carries a *fixed* weekly pattern via `working_days`, which
 * covers an office on Mon-Fri. It cannot express a pattern that changes from one
 * week to the next, so hospitals, manufacturing, security and hotels — where
 * staff cycle through morning/afternoon/night — had no way to model their
 * schedule at all.
 *
 * The cycle is measured in **days**, not weeks. A week-based model looks like
 * the more natural fit and is not: it can express "week 1 mornings, week 2
 * nights" but cannot express a 4-on-4-off pattern, whose 8-day cycle never
 * aligns to a 7-day week. Day offsets cover both, and a weekly rotation is just
 * a cycle whose length happens to be a multiple of 7.
 *
 * Resolution is `(date - anchor_date) mod cycle_days` → the step at that offset.
 * A step with a null `shift_id` is a rest day, which is a real part of a
 * rotation and not an absence of data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rotations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            // Length of the repeating cycle. 7 = weekly, 14 = fortnightly,
            // 21 = three-week, 8 = four-on-four-off.
            $table->unsignedSmallInteger('cycle_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Soft delete for the same reason as `shifts`: historical attendance
            // matching refers back to the pattern an employee was working.
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('shift_rotation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_rotation_id')->constrained()->cascadeOnDelete();
            // 0-based position within the cycle.
            $table->unsignedSmallInteger('day_offset');
            // Null = rest day.
            $table->foreignId('shift_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One shift per position: the pattern must be unambiguous.
            $table->unique(['shift_rotation_id', 'day_offset'], 'rotation_step_unique');
            $table->index('tenant_id');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            // A rotation is assigned through the *same* table as a plain shift,
            // so "what is this employee working on date X" has one answer and
            // one place to look. Splitting it into a parallel table would let
            // attendance matching consult one source and silently miss the
            // other.
            $table->foreignId('shift_rotation_id')->nullable()->after('shift_id')
                ->constrained()->cascadeOnDelete();
            // The date at which day_offset 0 falls. Required for a rotation,
            // meaningless for a plain shift.
            $table->date('anchor_date')->nullable()->after('effective_to');
        });

        // shift_id becomes nullable: a row now holds either a shift or a
        // rotation. Done as raw SQL because changing a constrained foreign key
        // column with doctrine/dbal is not available here.
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropForeign(['shift_rotation_id']);
            $table->dropColumn(['shift_rotation_id', 'anchor_date']);
        });

        Schema::dropIfExists('shift_rotation_steps');
        Schema::dropIfExists('shift_rotations');
    }
};
