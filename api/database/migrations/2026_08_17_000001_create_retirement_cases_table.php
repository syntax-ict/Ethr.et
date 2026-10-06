<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retirement *process* that pension contributions (already computed per
 * payroll period, see `PensionCalculator`) had no workflow around: initiated
 * → reviewed → approved/rejected → finalized. `service_years` and
 * `eligible_retirement_date` are computed and stored at initiation from
 * objective facts (`hire_date`, `date_of_birth`, the tenant's configurable
 * `retirement_age` setting) — this table deliberately does not attempt to
 * compute a pension *benefit amount*; that is a statutory calculation this
 * codebase is not in a position to get right without a verified source
 * (same caution as the tax-bracket currency question elsewhere in this
 * project). `finalized_transition_id` links to the `EmployeeTransition` row
 * created when the case is finalized, so the retirement is a real status
 * change through the same machinery every other transition uses, not a
 * parallel status field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retirement_cases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('retirement_type', 20);
            $table->string('status', 20)->default('initiated');
            $table->decimal('service_years', 5, 2);
            $table->date('eligible_retirement_date')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('decision', 20)->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_transition_id')->nullable()->constrained('employee_transitions')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retirement_cases');
    }
};
