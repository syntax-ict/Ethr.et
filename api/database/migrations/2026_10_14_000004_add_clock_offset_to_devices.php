<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How far a device's clock is from the server's, as last seen on a punch
     * it sent: positive when the device runs ahead. Null until a punch has
     * arrived close enough to when it was made to say (DeviceClock).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->integer('clock_offset_seconds')->nullable();
            $table->timestamp('clock_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['clock_offset_seconds', 'clock_checked_at']);
        });
    }
};
