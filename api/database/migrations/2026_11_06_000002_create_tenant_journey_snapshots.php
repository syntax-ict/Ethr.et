<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per organisation: where it is on the road from sign-up to using
 * ETHR, and how healthy that road looks (docs/operations/TENANT-JOURNEY.md).
 *
 * Platform data, not tenant data: the model carries no BelongsToTenant scope
 * and every read states `tenant_id`. It is a snapshot because the readiness
 * score alone is about thirty queries, too many to run per row of a list.
 * Milestone dates are derived from history when a snapshot is computed, so
 * nothing else has to write here and every existing organisation is back-filled
 * by its first refresh.
 *
 * Index names are short on purpose: MariaDB refuses names over 64 characters,
 * which SQLite does not, so the tests would not catch a long one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_journey_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30);
            $table->timestamp('stage_reached_at')->nullable();
            $table->string('lifecycle', 20);
            $table->string('health_state', 10);
            $table->json('reasons');
            $table->json('milestones');
            $table->json('metrics');
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->string('readiness_level', 20);
            $table->json('readiness_gaps');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->unique('tenant_id', 'tjs_tenant_unique');
            $table->index('stage', 'tjs_stage_index');
            $table->index('health_state', 'tjs_health_index');
            $table->index('computed_at', 'tjs_computed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_journey_snapshots');
    }
};
