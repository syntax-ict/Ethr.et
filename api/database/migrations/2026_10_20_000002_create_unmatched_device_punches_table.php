<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device punches whose badge matched no employee, kept instead of dropped.
 *
 * Every device path (pull, vendor webhooks, ADMS) logged an unknown or
 * ambiguous badge and discarded the punch, so the day was lost for good once
 * the badge was finally mapped (blueprint assessment AT3, AT20). A held punch
 * keeps the idempotency key it would have been recorded under, so replaying
 * it after the badge is assigned can never record it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unmatched_device_punches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('badge', 64);
            $table->string('punch_type', 20);
            $table->string('occurred_raw', 64);
            $table->string('idempotency_key', 191);
            $table->string('status', 20)->default('pending');
            $table->string('refusal', 255)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'idempotency_key'], 'unmatched_punch_device_key');
            $table->index(['tenant_id', 'status', 'device_id', 'badge'], 'unmatched_punch_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatched_device_punches');
    }
};
