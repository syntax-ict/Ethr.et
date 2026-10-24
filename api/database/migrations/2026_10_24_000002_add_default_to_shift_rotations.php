<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rotation can be the organisation's default schedule.
 *
 * Until now only a shift could be the default, and a shift has one start and
 * end for all its days. The most common Ethiopian week (Monday to Friday
 * 08:00-17:00 plus Saturday 08:00-12:00) is two shifts on a weekly cycle, so
 * the six-day profile made "Weekday" the default and every Saturday punch was
 * rated against 08:00-17:00 and paid as rest-day overtime.
 *
 * A default rotation applies to everyone with no assignment of their own,
 * from `anchor_date` (the day that is step 0). Crew rotations never become the
 * default: each crew needs its own anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_rotations', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->date('anchor_date')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('shift_rotations', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'anchor_date']);
        });
    }
};
