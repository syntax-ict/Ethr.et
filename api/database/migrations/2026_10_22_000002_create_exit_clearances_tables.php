<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit clearance (blueprint L10): a checklist that starts when an employee
 * resigns, is terminated or retires, from the organisation's template. Each
 * item is ticked by the people it belongs to and records who and when.
 *
 * Not an asset register: asset management is deferred to v2.0
 * (PRODUCT_VISION.md §20). Property return is a checklist line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exit_clearances', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_transition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exit_status', 20);
            $table->date('leaving_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'exit_clearances_tenant_status');
            $table->index('employee_id', 'exit_clearances_employee');
        });

        Schema::create('exit_clearance_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_clearance_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('key', 40)->nullable();
            $table->string('label', 160);
            $table->string('owner', 20);
            $table->string('kind', 20)->default('manual');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('exit_clearance_id', 'exit_clearance_items_clearance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_clearance_items');
        Schema::dropIfExists('exit_clearances');
    }
};
