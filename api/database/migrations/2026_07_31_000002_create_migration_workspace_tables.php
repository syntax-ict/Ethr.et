<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce-migration staging: a batch of discovered people (from a device, a
 * spreadsheet, or a directory) held for human review before any of them become
 * employees. Each staging row carries its resolver verdict and a chosen action
 * (merge / create / skip / defer). See ONBOARDING_V2.md decision D6.
 *
 * Both tables are transient staging per the CLAUDE.md soft-delete policy
 * ("Import staging data — hard delete after 7 days") and are pruned by
 * CleanupExpiredDataJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('source_type', 30);          // device | csv | manual
            $table->string('source_ref', 100)->nullable();
            $table->string('status', 20)->default('reviewing'); // reviewing | committed
            $table->json('totals')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('migration_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('migration_batches')->cascadeOnDelete();

            $table->json('raw');                          // source signals as received
            $table->string('display_name')->nullable();
            $table->string('external_identifier')->nullable();

            $table->string('match_outcome', 20)->nullable();   // matched | probable | ambiguous | new
            $table->decimal('match_confidence', 3, 2)->nullable();
            $table->json('candidates')->nullable();
            $table->unsignedBigInteger('resolved_employee_id')->nullable();

            $table->string('action', 20)->default('pending');  // merge | create | skip | defer | pending
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id']);
            $table->foreign('resolved_employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_staging_rows');
        Schema::dropIfExists('migration_batches');
    }
};
