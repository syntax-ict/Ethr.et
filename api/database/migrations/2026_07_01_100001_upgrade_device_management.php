<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->after('status');
            $table->boolean('auto_sync')->default(true)->after('webhook_token');
            $table->unsignedSmallInteger('sync_interval_minutes')->default(5)->after('auto_sync');
            $table->string('location_description')->nullable()->after('name');
        });

        Schema::create('device_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20); // success, partial, failed, offline
            $table->string('triggered_by', 20); // schedule, manual, webhook
            $table->unsignedInteger('events_found')->default(0);
            $table->unsignedInteger('events_processed')->default(0);
            $table->unsignedInteger('events_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'device_id']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sync_logs');

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['webhook_token', 'auto_sync', 'sync_interval_minutes', 'location_description']);
        });
    }
};
