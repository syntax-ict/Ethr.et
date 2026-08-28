<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-term/probation/permanent contract records. A renewal creates a new
 * row linked back via `renewed_from_id` rather than mutating dates in place —
 * the contract history (who was on what terms, when) stays intact the same
 * way `personnel_actions`/`disciplinary_cases` keep append-only history
 * instead of overwriting state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->string('contract_type', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->bigInteger('salary_cents')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('renewed_from_id')->nullable()->constrained('employee_contracts')->nullOnDelete();
            $table->date('ended_at')->nullable();
            $table->text('end_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
