<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('enabled_methods')->comment('["biometric","mobile","qr","kiosk","web","manual","csv"]');
            $table->boolean('geofence_required')->default(false);
            $table->boolean('mobile_photo_required')->default(false);
            $table->boolean('kiosk_pin_required')->default(false);
            $table->unsignedSmallInteger('qr_expiry_minutes')->default(30);
            $table->boolean('qr_auto_refresh')->default(true);
            $table->unsignedSmallInteger('qr_single_use_limit')->default(0)->comment('0=unlimited');
            $table->unsignedSmallInteger('mobile_accuracy_threshold_meters')->default(100);
            $table->boolean('offline_sync_enabled')->default(true);
            $table->unsignedSmallInteger('kiosk_auto_reset_seconds')->default(4);
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('kiosk_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->string('admin_pin', 255)->comment('bcrypt hashed');
            $table->string('device_identifier', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'kiosk_pin')) {
                $table->string('kiosk_pin', 255)->nullable()->after('badge_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'kiosk_pin')) {
                $table->dropColumn('kiosk_pin');
            }
        });

        Schema::dropIfExists('kiosk_sessions');
        Schema::dropIfExists('attendance_settings');
    }
};
