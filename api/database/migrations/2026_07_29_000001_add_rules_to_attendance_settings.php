<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_period_minutes')->default(15)->after('kiosk_auto_reset_seconds');
            $table->unsignedSmallInteger('ot_daily_cap_minutes')->default(120)->after('grace_period_minutes');
            $table->unsignedTinyInteger('confidence_threshold')->default(70)->after('ot_daily_cap_minutes');
        });

        // Backfill from the tenant.settings JSON where these rules previously
        // lived, so any tenant that already configured them keeps their values
        // once the source of truth moves to attendance_settings.
        foreach (DB::table('tenants')->select('id', 'settings')->get() as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true);
            if (! is_array($settings)) {
                continue;
            }

            $updates = [];
            foreach (['grace_period_minutes', 'ot_daily_cap_minutes', 'confidence_threshold'] as $key) {
                if (isset($settings[$key]) && is_numeric($settings[$key])) {
                    $updates[$key] = (int) $settings[$key];
                }
            }

            if ($updates !== []) {
                DB::table('attendance_settings')
                    ->where('tenant_id', $tenant->id)
                    ->update($updates);
            }
        }
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['grace_period_minutes', 'ot_daily_cap_minutes', 'confidence_threshold']);
        });
    }
};
