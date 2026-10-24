<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-device liveness: before a selfie is taken, the phone checks that one
 * real face is in front of it (a blink or a head turn, asked at random). It
 * runs entirely on the phone; only the outcome is sent. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('liveness_check')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('liveness_check');
        });
    }
};
