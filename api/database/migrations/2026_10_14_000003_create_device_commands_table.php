<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commands ETHR sends to a device: set its clock, add or update a person,
     * remove a person. Until now device management was one-way — punches came
     * in, nothing went out, so every new hire had to be enrolled on every
     * terminal by hand and clocks drifted unchecked.
     *
     * A device takes commands only when an administrator turns
     * `commands_enabled` on: removing a person from a terminal deletes the
     * fingerprints and face enrolled there, which nobody should get by surprise.
     */
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Named: the generated names pass MariaDB's 64-character limit.
            $table->index(['tenant_id', 'device_id', 'status'], 'device_commands_tenant_device_status_index');
            $table->index(['device_id', 'employee_id', 'status'], 'device_commands_device_employee_status_index');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('commands_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('commands_enabled');
        });

        Schema::dropIfExists('device_commands');
    }
};
