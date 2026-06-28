<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->unsignedSmallInteger('early_departure_minutes')->default(15);
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->string('working_days', 20)->default('1,2,3,4,5');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'assignable_type', 'assignable_id'], 'shift_assign_tenant_poly');
            $table->index(['assignable_type', 'assignable_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->string('adapter_type', 30);
            $table->text('connection_config');
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->string('source', 30);
            $table->unsignedSmallInteger('confidence_score')->default(50);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('geofence_verified')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('offline_token', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'employee_id', 'date']);
            $table->index(['tenant_id', 'date']);
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('proposed_check_in')->nullable();
            $table->timestamp('proposed_check_out')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('approval_chain')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->date('date');
            $table->boolean('ethiopian_calendar')->default(false);
            $table->boolean('recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
