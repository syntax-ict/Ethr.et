<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disciplinary cases: offence → investigation → decision → sanction → appeal.
 * One row per case; the investigation phase is a growing `investigation_notes`
 * timeline (append-only, same shape as `personnel_actions.changes` — a JSON
 * snapshot rather than a second table, since notes are always read alongside
 * their case and never queried independently). Decision, sanction and appeal
 * are separate nullable groups of columns because each is optional and set at
 * a different, later point in the case's life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->string('category', 40);
            $table->text('description');
            $table->date('incident_date');
            $table->string('status', 20)->default('reported');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('investigation_notes')->nullable();

            $table->string('decision', 20)->nullable();
            $table->text('decision_notes')->nullable();
            $table->date('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('sanction_type', 30)->nullable();
            $table->text('sanction_details')->nullable();
            $table->date('sanction_effective_date')->nullable();

            $table->string('appeal_status', 20)->nullable();
            $table->text('appeal_grounds')->nullable();
            $table->date('appeal_filed_at')->nullable();
            $table->text('appeal_decision_notes')->nullable();
            $table->date('appeal_decided_at')->nullable();
            $table->foreignId('appeal_decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
    }
};
