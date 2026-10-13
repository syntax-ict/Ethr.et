<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The punching assistant's organisation-wide switches.
     *
     * - Reminders before a shift starts and ends are on by default: they only
     *   ever tell someone something, on their own device.
     * - Auto check-out closes a check-in left open past the shift's end, at
     *   the scheduled end, flagged for review. Off by default: it writes
     *   attendance, so an organisation turns it on deliberately.
     * - Geofence auto check-in lets an employee who opted in be checked in
     *   when they open the app at work during the check-in window. Off by
     *   default, for the same reason.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(true);
            $table->unsignedSmallInteger('reminder_lead_minutes')->default(10);
            $table->boolean('auto_checkout_enabled')->default(false);
            $table->unsignedSmallInteger('auto_checkout_after_minutes')->default(120);
            $table->boolean('geofence_auto_checkin_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'reminders_enabled',
                'reminder_lead_minutes',
                'auto_checkout_enabled',
                'auto_checkout_after_minutes',
                'geofence_auto_checkin_enabled',
            ]);
        });
    }
};
