<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered attendance devices: one passkey per employee, on the phone they
 * punch with.
 *
 * A punch used to name its device by a random id the browser kept, which a
 * second browser, a private window or cleared site data replaced at will, so
 * a friend could punch for a colleague from any phone. A passkey's private key
 * stays in the phone's secure hardware and cannot be copied; the server keeps
 * only its public key and checks a fresh signature on every punch
 * (AttendanceDeviceGate).
 *
 * Challenges are single use and short lived, claimed with one conditional
 * update so two requests cannot both spend one. They live in a table, not the
 * cache, because the cache store on shared hosting may be a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 1024);
            $table->char('credential_id_hash', 64);
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('rp_id', 253);
            $table->string('aaguid', 36)->nullable();
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);
            $table->char('install_hash', 32)->nullable();
            $table->char('cookie_hash', 64)->nullable();
            $table->string('label', 120)->nullable();
            $table->string('status', 12)->default('pending');
            $table->boolean('enrolled_inside_geofence')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['rp_id', 'credential_id_hash'], 'att_dev_cred_uq');
            $table->index(['tenant_id', 'employee_id', 'status'], 'att_dev_emp_idx');
            $table->index(['tenant_id', 'install_hash'], 'att_dev_install_idx');
            $table->index(['tenant_id', 'cookie_hash'], 'att_dev_cookie_idx');
        });

        Schema::create('attendance_device_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->char('challenge_hash', 64)->unique();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'expires_at'], 'att_dev_chal_exp_idx');
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->string('device_binding', 8)->default('off');
            $table->string('attendance_rp_id', 253)->nullable();
            $table->json('office_networks')->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->date('attendance_device_exempt_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('attendance_device_exempt_until');
        });
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['device_binding', 'attendance_rp_id', 'office_networks']);
        });
        Schema::dropIfExists('attendance_device_challenges');
        Schema::dropIfExists('attendance_devices');
    }
};
