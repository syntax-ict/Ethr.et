<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grade→step salary scale that `salary_step` (on `employees`) and
 * `salary_step_increment` personnel actions previously had nothing to
 * validate against — `salary_step` was a free 1–100 integer with no defined
 * meaning. One row per (grade, step) pair; `PersonnelActionService` looks
 * this up when a salary-step change is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_salary_steps', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->bigInteger('salary_cents');
            $table->timestamps();

            $table->unique(['grade_id', 'step']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_salary_steps');
    }
};
