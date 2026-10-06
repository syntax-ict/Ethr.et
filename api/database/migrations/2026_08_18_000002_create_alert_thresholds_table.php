<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.7 — configurable alert thresholds. The compliance card (6.5) hard-
 * codes its own "count > 0 is a warning" tone; this table lets a tenant define
 * real numeric rules ("alert when turnover exceeds 5%", "alert when today's
 * attendance rate falls below 90%") over the same metrics
 * ExecutiveDashboardService already computes — see AlertEvaluator. `metric` is
 * a fixed key from AlertEvaluator::METRICS, not free text, so evaluation never
 * has to guess what a stored threshold means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_thresholds', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('metric', 40);
            $table->string('operator', 10);
            $table->decimal('threshold_value', 12, 2);
            $table->string('severity', 20)->default('warning');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_thresholds');
    }
};
