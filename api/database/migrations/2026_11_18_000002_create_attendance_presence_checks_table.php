<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per presence check actually sent to a phone during a shift.
 *
 * The times of the checks are derived from the record, never stored; a row
 * exists only once a push has gone out, and holds the answer. It is a table
 * of its own because a record's `metadata` is rewritten whole by every punch
 * and would lose an answer that raced one. Rows are purged after 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_presence_checks', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();
            $table->string('status', 12)->default('sent');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 1)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->string('device_verdict', 40)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['attendance_record_id', 'slot'], 'presence_checks_record_slot_unique');
            $table->index(['tenant_id', 'status', 'expires_at'], 'presence_checks_tenant_status_expires_index');
            $table->index(['tenant_id', 'employee_id', 'sent_at'], 'presence_checks_tenant_employee_sent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_presence_checks');
    }
};
