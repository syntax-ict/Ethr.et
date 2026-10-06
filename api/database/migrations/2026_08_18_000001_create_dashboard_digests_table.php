<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.6 — dashboard scheduling / digest delivery. Deliberately its own
 * table rather than reusing `scheduled_reports`: a digest has no `SavedReport`
 * behind it (it's `ExecutiveDashboardService::overview()`/`complianceSnapshot()`
 * computed fresh per run, not a stored report definition) and carries a
 * `branch_id` for the same regional-scoping the dashboard endpoints already
 * enforce — a `scheduled_reports` row has neither. `recipients` follows the
 * exact convention `scheduled_reports.recipients` already established: a JSON
 * array of free-text email addresses, not necessarily tenant user accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_digests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('frequency', 20);
            $table->json('recipients');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_digests');
    }
};
