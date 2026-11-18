<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many presence checks a shift may carry: 0 (the default) is off, 3 is
 * the most. A branch may set its own. See App\Services\Attendance\PresenceChecks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('presence_checks_per_shift')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table): void {
            $table->dropColumn('presence_checks_per_shift');
        });
    }
};
