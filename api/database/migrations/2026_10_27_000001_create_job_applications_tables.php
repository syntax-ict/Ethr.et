<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Applicants for a vacancy, from first contact to hire.
     *
     * `employee_id` is unique: an application becomes at most one employee,
     * and the hire endpoint locks the row before checking it, so a retried or
     * doubled request returns the same employee instead of creating a second.
     *
     * The offer is held on the application rather than in its own table:
     * there is one offer at a time, and moving the application back to the
     * offer stage replaces it and clears its approval.
     *
     * `job_application_events` is the history - stage moves, notes, interview
     * scores, the offer approval and the hire - appended and never edited.
     * `erased_at` marks an application whose personal details were removed
     * once it was closed; the row and its stage history stay so the selection
     * record still adds up.
     */
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('source', 20);
            $table->text('cover_note')->nullable();
            $table->string('stage', 20)->default('applied');
            $table->date('offer_start_date')->nullable();
            $table->bigInteger('offer_salary_cents')->nullable();
            $table->text('offer_notes')->nullable();
            $table->foreignId('offer_made_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('offer_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offer_approved_at')->nullable();
            $table->foreignId('employee_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('hired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hired_at')->nullable();
            $table->string('closed_reason', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('erased_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'vacancy_id', 'stage']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('job_application_events', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'job_application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_events');
        Schema::dropIfExists('job_applications');
    }
};
