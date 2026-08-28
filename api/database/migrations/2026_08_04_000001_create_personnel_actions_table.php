<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personnel actions: the immutable civil-service employment history
 * (promotion, transfer, acting assignment, delegation, secondment, salary step
 * increment, …). Each row snapshots what changed as `changes` so the record
 * stays accurate even after the referenced position/grade/unit is later renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_actions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();
            $table->json('changes');
            $table->boolean('is_temporary')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });

        // Current salary step within the employee's grade scale — set by
        // SALARY_STEP_INCREMENT actions. Nullable: organizations that do not run
        // a step-based scale simply never populate it.
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('salary_step')->nullable()->after('salary_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('salary_step');
        });

        Schema::dropIfExists('personnel_actions');
    }
};
