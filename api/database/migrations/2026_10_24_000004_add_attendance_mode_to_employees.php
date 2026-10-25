<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an employee is expected to punch: at their branch (`office`), at any
 * site they are assigned (`field`), or from wherever they work (`remote`).
 *
 * The branch geofence was the only place rule, so a field officer visiting
 * farms or a salesperson at client offices was either refused every day or the
 * whole organisation had to switch the geofence off. Office is the default, so
 * nobody's rules change until someone chooses otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_mode', 10)->default('office');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('attendance_mode');
        });
    }
};
